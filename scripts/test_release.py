import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.dont_write_bytecode = True

SCRIPT = Path(__file__).with_name("release.py")
ENV = {
    **os.environ,
    "GIT_CONFIG_GLOBAL": "/dev/null",
    "GIT_CONFIG_NOSYSTEM": "1",
    "GIT_TERMINAL_PROMPT": "0",
    "PYTHONDONTWRITEBYTECODE": "1",
}


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.repo = self.base / "repo"
        self.remote = self.base / "remote.git"
        self.repo.mkdir()
        self.git("init", "-q", "--initial-branch=main")
        self.git("config", "user.name", "Release Test")
        self.git("config", "user.email", "release@example.invalid")
        self.git("config", "commit.gpgsign", "false")
        self.run_cmd(["git", "init", "-q", "--bare", str(self.remote)])
        self.git("remote", "add", "origin", str(self.remote))
        (self.repo / "scripts").mkdir()
        shutil.copyfile(SCRIPT, self.repo / "scripts/release.py")
        self.config = {
            "branch": "main",
            "sign_tag": False,
            "checks": [],
            "version_files": [],
        }
        self.write_config()
        self.notes()
        self.commit()
        self.git("tag", "-a", "1.0.0", "-m", "Previous release")
        self.git("push", "-q", "origin", "main", "1.0.0")
        (self.repo / "change.txt").write_text("new release")
        self.commit()
        self.git("tag", "scratch-local")

    def run_cmd(self, cmd, check=True, cwd=None, env=None):
        r = subprocess.run(
            cmd,
            cwd=cwd or self.repo,
            env=env or ENV,
            capture_output=True,
            text=True,
            check=False,
        )
        if check and r.returncode:
            self.fail(str(cmd) + "\n" + r.stdout + r.stderr)
        return r

    def git(self, *args):
        return self.run_cmd(["git", *args]).stdout.strip()

    def commit(self):
        self.git("add", ".")
        self.git("commit", "-q", "-m", "Prepare release")

    def write_config(self):
        (self.repo / ".release.json").write_text(json.dumps(self.config))

    def notes(self, s=None):
        (self.repo / "CHANGELOG.md").write_text(
            s
            or "# Changelog\n\n## [1.0.1] - 2026-10-05\n\n### Fixed\n\n"
            "- A useful fix.\n\n## [1.0.0] - 2026-10-01\n\n- Old notes.\n"
        )

    def release(self, *args):
        return self.run_cmd(
            [sys.executable, str(self.repo / "scripts/release.py"), *args], check=False
        )

    def assert_rejected(self, fragment, *args):
        r = self.release(*(args or ("1.0.1",)))
        self.assertNotEqual(r.returncode, 0, r.stdout)
        self.assertIn(fragment, r.stderr)
        self.assertNotIn("1.0.1", self.git("tag").splitlines())
        return r

    def test_dry_run_does_not_run_checks_or_modify_refs(self):
        self.config["checks"] = [
            [sys.executable, "-c", "open('check-ran','w').write('yes')"]
        ]
        self.write_config()
        self.commit()
        before = self.git("show-ref")
        content = (self.repo / "CHANGELOG.md").read_bytes()
        r = self.release("1.0.1", "--dry-run")
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(before, self.git("show-ref"))
        self.assertEqual(content, (self.repo / "CHANGELOG.md").read_bytes())
        self.assertFalse((self.repo / "check-ran").exists())

    def test_publish_only_requested_tag(self):
        r = self.release("1.0.1")
        self.assertEqual(r.returncode, 0, r.stderr)
        refs = self.git("ls-remote", "origin")
        self.assertIn("refs/tags/1.0.1", refs)
        self.assertNotIn("scratch-local", refs)
        self.assertEqual(self.git("cat-file", "-t", "1.0.1"), "tag")

    def test_signed_release(self):
        key = self.base / "signing-key"
        self.run_cmd(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-f", str(key)])
        allowed = self.base / "allowed-signers"
        allowed.write_text(
            "release@example.invalid " + key.with_suffix(".pub").read_text()
        )
        self.git("config", "gpg.format", "ssh")
        self.git("config", "user.signingkey", str(key))
        self.git("config", "gpg.ssh.allowedSignersFile", str(allowed))
        self.config["sign_tag"] = True
        self.write_config()
        self.commit()
        r = self.release("1.0.1")
        self.assertEqual(r.returncode, 0, r.stderr)
        self.git("verify-tag", "1.0.1")

    def test_dirty_tree(self):
        (self.repo / "unrelated.txt").write_text("do not commit")
        self.assert_rejected("Working tree is dirty")

    def test_wrong_branch(self):
        self.git("checkout", "-q", "-b", "feature")
        self.assert_rejected("Release from main")

    def test_prefix_and_invalid_semver(self):
        for v in ["v1.0.1", "1.0", "01.0.1", "1.0.1-beta.01"]:
            with self.subTest(v=v):
                self.assert_rejected(
                    "prefix" if v != "1.0.1-beta.01" else "leading zeroes", v
                )

    def test_missing_empty_duplicate_notes(self):
        for content, message in [
            ("# Changelog\n", "exactly one"),
            ("## [1.0.1]\n\n### Fixed\n", "empty"),
            ("## [1.0.1]\n- One\n## [1.0.1]\n- Two\n", "exactly one"),
        ]:
            self.notes(content)
            self.assert_rejected(message, "1.0.1", "--notes")

    def test_legacy_headers_fences_and_exact_version_match(self):
        self.notes(
            "## 1.0.1 (2026-10-05)\n\n- Useful notes\n\n"
            "```md\n## [2.0.0]\n```\n\n## [1.0.10]\n- Not this version\n"
        )
        r = self.release("1.0.1", "--notes")
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("## [2.0.0]", r.stdout)
        self.assertNotIn("Not this version", r.stdout)

    def test_version_mismatch(self):
        (self.repo / "package.json").write_text('{"version":"1.0.0"}')
        self.config["version_files"] = ["package.json"]
        self.write_config()
        self.commit()
        self.assert_rejected("package.json version must equal")

    def test_lockfile_root_version_mismatch(self):
        (self.repo / "package-lock.json").write_text(
            '{"version":"1.0.1","packages":{"":{"version":"1.0.0"}}}'
        )
        self.config["version_files"] = ["package-lock.json"]
        self.write_config()
        self.commit()
        self.assert_rejected("root package version")

    def test_ci_notes_version_mismatch(self):
        (self.repo / "package.json").write_text('{"version":"1.0.0"}')
        self.config["version_files"] = ["package.json"]
        self.write_config()
        self.assert_rejected("package.json version must equal", "1.0.1", "--notes")

    def test_ci_notes_stable_app_rejects_prerelease(self):
        self.notes("## [1.1.0-beta.1]\n- Beta.\n")
        self.config["stable_only"] = True
        self.write_config()
        self.assert_rejected("numeric Apple", "1.1.0-beta.1", "--notes")

    def test_old_version_rejected(self):
        self.notes("## [0.9.0]\n- Older version.\n")
        self.commit()
        self.assert_rejected("must be newer", "0.9.0")

    def test_stable_app_rejects_prerelease(self):
        self.notes("## [1.1.0-beta.1]\n- Beta.\n")
        self.config["stable_only"] = True
        self.write_config()
        self.commit()
        self.assert_rejected("numeric Apple", "1.1.0-beta.1")

    def test_checks_failure_stops_tagging(self):
        self.config["checks"] = [[sys.executable, "-c", "raise SystemExit(1)"]]
        self.write_config()
        self.commit()
        self.assert_rejected("non-zero exit status")

    def test_checks_mutation_stops_tagging(self):
        self.config["checks"] = [
            [sys.executable, "-c", "open('change.txt','w').write('modified')"]
        ]
        self.write_config()
        self.commit()
        self.assert_rejected("Working tree is dirty")

    def test_checks_commit_stops_tagging(self):
        self.config["checks"] = [["git", "commit", "--allow-empty", "-m", "unexpected"]]
        self.write_config()
        self.commit()
        self.assert_rejected("HEAD changed")

    def test_remote_divergence(self):
        clone = self.base / "other"
        self.run_cmd(
            ["git", "clone", "-q", "--branch", "main", str(self.remote), str(clone)]
        )
        self.run_cmd(["git", "config", "user.name", "Other"], cwd=clone)
        self.run_cmd(
            ["git", "config", "user.email", "other@example.invalid"], cwd=clone
        )
        self.run_cmd(
            ["git", "commit", "--allow-empty", "-q", "-m", "Remote advancement"],
            cwd=clone,
        )
        self.run_cmd(["git", "push", "-q"], cwd=clone)
        self.assert_rejected("reconcile main")

    def test_published_tag_cannot_be_overwritten(self):
        self.git("tag", "-a", "1.0.1", "-m", "already released")
        self.git("push", "-q", "origin", "1.0.1")
        r = self.release("1.0.1")
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("already published", r.stderr)

    def test_atomic_push_failure_and_retry(self):
        remote_before = self.git("ls-remote", "origin", "refs/heads/main")
        hook = self.remote / "hooks/pre-receive"
        hook.write_text("#!/bin/sh\nexit 1\n")
        hook.chmod(0o755)
        r = self.release("1.0.1")
        self.assertNotEqual(r.returncode, 0)
        self.assertEqual(
            remote_before, self.git("ls-remote", "origin", "refs/heads/main")
        )
        self.assertNotIn("refs/tags/1.0.1", self.git("ls-remote", "origin"))
        self.assertIn("1.0.1", self.git("tag").splitlines())
        hook.unlink()
        r = self.release("1.0.1")
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("Reuse local tag", r.stdout)

    def test_wrong_local_tag_cannot_be_reused(self):
        self.git("tag", "-a", "1.0.1", "1.0.0", "-m", "wrong")
        r = self.release("1.0.1")
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("another commit", r.stderr)

    def test_lightweight_local_tag_cannot_be_reused(self):
        self.git("tag", "1.0.1")
        r = self.release("1.0.1")
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("must be annotated", r.stderr)

    def test_semver_order(self):
        spec = importlib.util.spec_from_file_location("release_helper", SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        versions = [
            "1.0.0-alpha",
            "1.0.0-alpha.1",
            "1.0.0-alpha.beta",
            "1.0.0-beta",
            "1.0.0-beta.2",
            "1.0.0-beta.11",
            "1.0.0-rc.1",
            "1.0.0",
            "1.0.1",
            "1.10.0",
            "2.0.0",
        ]
        for a, b in zip(versions, versions[1:]):
            self.assertLess(module.compare_versions(a, b), 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
