# Releasing

A release is a `v*` tag on `main`. The tag must equal the `VERSION` file, and that file is the only place the version lives: the CLI prints it and the panel compares it with GitHub's latest release. `setup.sh`, `install.sh` and `panel:update` install the newest `v*` tag they find (or `--version=<tag>`), and the scripts themselves are served from the latest release.

## Steps

1. Make sure `main` is green and every change is in `CHANGELOG.md` under *Unreleased*.
2. Bump `VERSION` (semantic versioning), move the *Unreleased* entries under the new version with the date, and commit: `Release 0.2.0`.
3. Tag and push the tag:

   ```bash
   git tag v0.2.0
   git push origin main v0.2.0
   ```

4. The release workflow (`.github/workflows/release.yml`) checks the tag against `VERSION`, runs the panel and CLI tests again, and creates the GitHub release with `setup.sh` and `install.sh` attached and generated notes. `releases/latest/download/` then serves the new scripts.

## What a release changes for users

- New servers set up from the panel install the release's CLI, then receive the panel's own build on first connection if it differs.
- Existing servers show **CLI update required** once the panel running the new version talks to them, because the build hash changed. The hourly check notifies every user.
- Hosted panels notify every user within an hour and show `sudo mandal panel:update` on the hosting server's page.
- Panels running from a checkout are updated with `git pull`, `composer install`, `bun run build`, `php artisan migrate` and `php artisan optimize`.

## Compatibility rules

- A panel must read the inventory of a server running the previous release without errors, because servers are updated after the panel. New fields are optional; the page says what is missing rather than failing.
- `setup.sh` and `install.sh` must stay idempotent and must keep a newer CLI they find on the server.
- Never remove a CLI command the previous panel release calls; deprecate it for one release.
