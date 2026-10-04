#!/usr/bin/env python3
"""Release a prepared commit, or extract its curated changelog entry. Stdlib only."""

import argparse
import functools
import json
import re
import shlex
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SEMVER = re.compile(
    r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?"
)


def fail(message):
    raise ValueError(message)


def version_parts(version):
    match = SEMVER.fullmatch(version)
    if not match:
        fail("Use X.Y.Z or X.Y.Z-prerelease, without a v prefix.")
    major, minor, patch, pre = match.groups()
    identifiers = pre.split(".") if pre else []
    if any(
        part.isdigit() and len(part) > 1 and part.startswith("0")
        for part in identifiers
    ):
        fail("Numeric prerelease identifiers cannot have leading zeroes.")
    return (int(major), int(minor), int(patch)), identifiers


def compare_versions(left, right):
    base_a, pre_a = version_parts(left)
    base_b, pre_b = version_parts(right)
    if base_a != base_b:
        return (base_a > base_b) - (base_a < base_b)
    if not pre_a or not pre_b:
        return (not pre_a) - (not pre_b)
    for a, b in zip(pre_a, pre_b):
        if a == b:
            continue
        if a.isdigit() and b.isdigit():
            return (int(a) > int(b)) - (int(a) < int(b))
        if a.isdigit() != b.isdigit():
            return -1 if a.isdigit() else 1
        return (a > b) - (a < b)
    return (len(pre_a) > len(pre_b)) - (len(pre_a) < len(pre_b))


def release_notes(version):
    lines = (ROOT / "CHANGELOG.md").read_text().splitlines()
    entries = []
    current = None
    fence = None
    for line in lines:
        stripped = line.lstrip()
        if stripped.startswith(("```", "~~~")):
            marker = stripped[:3]
            if fence is None:
                fence = marker
            elif fence == marker:
                fence = None
        if fence is None and line.startswith("## "):
            if current is not None:
                entries.append(current)
            match = re.match(r"^## (?:\[([^\]]+)\]|(\S+))", line)
            current = [match.group(1) or match.group(2), []] if match else None
        elif current is not None:
            current[1].append(line)
    if current is not None:
        entries.append(current)
    matches = [body for heading, body in entries if heading == version]
    if len(matches) != 1:
        fail(
            f"CHANGELOG.md must contain exactly one entry for {version} "
            f"(found {len(matches)})."
        )
    notes = "\n".join(matches[0]).strip()
    if not any(
        line.strip()
        and not line.lstrip().startswith(("#", "<!--"))
        and line.strip() not in ("---", "***")
        for line in notes.splitlines()
    ):
        fail(f"The changelog entry for {version} is empty.")
    return notes + "\n"


def git(*arguments, required=True):
    result = subprocess.run(
        ["git", *arguments], cwd=ROOT, text=True, capture_output=True, check=False
    )
    if required and result.returncode:
        fail(result.stderr.strip() or f"git {' '.join(arguments)} failed")
    return result.stdout.strip() if result.returncode == 0 else None


def require_clean():
    if git("status", "--porcelain"):
        fail(
            "Working tree is dirty. Review and commit intended changes before "
            "releasing; do not stage unrelated work."
        )


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("version", help="Exact release version, without v")
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Validate and preview; do not run checks, tag or push",
    )
    parser.add_argument(
        "--notes",
        action="store_true",
        help="Print validated release notes only (for CI)",
    )
    args = parser.parse_args()
    version_parts(args.version)
    config = json.loads((ROOT / ".release.json").read_text())
    if config.get("stable_only") and "-" in args.version:
        fail(
            "This app uses numeric Apple bundle versions; "
            "release X.Y.Z without a prerelease suffix."
        )
    for file in config.get("version_files", []):
        data = json.loads((ROOT / file).read_text())
        if data.get("version") != args.version:
            fail(f"{file} version must equal {args.version}.")
        if (
            file == "package-lock.json"
            and data.get("packages", {}).get("", {}).get("version", args.version)
            != args.version
        ):
            fail("package-lock.json root package version does not match.")
    notes = release_notes(args.version)
    if args.notes:
        print(notes, end="")
        return

    branch = config["branch"]
    if git("branch", "--show-current") != branch:
        fail(f"Release from {branch}; merge reviewed changes there first.")
    require_clean()
    head = git("rev-parse", "HEAD")
    local_tag = git(
        "rev-parse", "--verify", f"refs/tags/{args.version}", required=False
    )
    if local_tag and git("rev-parse", f"refs/tags/{args.version}^{{commit}}") != head:
        fail(
            f"Local tag {args.version} already points to another commit. "
            "Never move a release tag."
        )
    if local_tag:
        if git("cat-file", "-t", local_tag) != "tag":
            fail("The existing local tag must be annotated.")
        if config.get("sign_tag", True):
            git("verify-tag", args.version)

    previous = []
    for tag in git("tag", "--merged", "HEAD").splitlines():
        value = tag.removeprefix("v")
        if tag == args.version or not SEMVER.fullmatch(value):
            continue
        version_parts(value)
        previous.append(value)
    if previous:
        latest = max(previous, key=functools.cmp_to_key(compare_versions))
        if compare_versions(args.version, latest) <= 0:
            fail(
                f"Version {args.version} must be newer than reachable release {latest}."
            )

    # Read remote refs without changing the checkout, even during dry-run.
    refs = git(
        "ls-remote", "origin", f"refs/heads/{branch}", f"refs/tags/{args.version}"
    )
    remote_head = None
    for line in refs.splitlines():
        commit, ref = line.split()
        if ref == f"refs/tags/{args.version}":
            fail(f"Tag {args.version} is already published. Never overwrite it.")
        if ref == f"refs/heads/{branch}":
            remote_head = commit
    if remote_head:
        result = subprocess.run(
            ["git", "merge-base", "--is-ancestor", remote_head, head],
            cwd=ROOT,
            capture_output=True,
            check=False,
        )
        if result.returncode:
            fail(
                f"Fetch origin and reconcile {branch}; "
                "remote branch is not an ancestor of HEAD."
            )

    print(f"Release {args.version} from {branch} at {head[:12]}\n\n{notes}", flush=True)
    checks = config.get("checks", [])
    for command in checks:
        print(f"Check: {shlex.join(command)}", flush=True)
        if not args.dry_run:
            subprocess.run(command, cwd=ROOT, check=True)
    tag_command = [
        "git",
        "tag",
        "-s" if config.get("sign_tag", True) else "-a",
        args.version,
        "-m",
        f"Release {args.version}",
    ]
    push_command = [
        "git",
        "push",
        "--atomic",
        "origin",
        f"HEAD:refs/heads/{branch}",
        f"refs/tags/{args.version}",
    ]
    print("Reuse local tag." if local_tag else shlex.join(tag_command), flush=True)
    print(shlex.join(push_command), flush=True)
    if args.dry_run:
        return
    require_clean()
    if git("rev-parse", "HEAD") != head:
        fail("HEAD changed during checks; review the new commit before releasing.")
    if not local_tag:
        subprocess.run(tag_command, cwd=ROOT, check=True)
    subprocess.run(push_command, cwd=ROOT, check=True)
    print(
        "Published the branch and this tag. "
        "Verify the release workflow before reporting success."
    )


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print(f"Release failed: {error}", file=sys.stderr)
        sys.exit(1)
