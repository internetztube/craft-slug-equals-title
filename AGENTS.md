# Agent instructions

Read `README.md` for this project’s purpose and usage. Follow the existing code patterns and use checks proportional to the change.

## Releases

Before preparing or publishing a release, read [RELEASING.md](RELEASING.md) and `.release.json`. Use an explicit version with no `v` prefix, write curated changelog notes, preserve history, prepare required version files/assets, and commit only intended changes. Use `python3 scripts/release.py X.Y.Z --dry-run`, then the same command without `--dry-run` when a release is requested. Verify the resulting workflow and release before reporting success. Do not use Shipmark, automatic commit-based bumping, `git push --tags`, or a second GitHub release creator. Code changes alone are not a request to publish.
