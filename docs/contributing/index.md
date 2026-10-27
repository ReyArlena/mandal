# Running the project locally

Mandal has two halves that are tested and developed separately:

- the **panel**, a Laravel 13 and Filament 5 application at the repository root
- the **server CLI**, Bash under `server/`, which the panel ships inside itself and installs on every server

## Requirements

- PHP 8.3 or newer with the `sqlite3`, `mbstring`, `intl`, `curl` and `zip` extensions, and Composer
- Bun
- bash, jq and shellcheck for the CLI
- Optionally [Incus](./incus) for running the CLI against a real Ubuntu server

## Setup

```bash
git clone https://github.com/ReyArlena/mandal.git
cd mandal
composer run setup      # dependencies, .env, APP_KEY, migrations, assets
php artisan db:seed     # the first user, admin@admin.com / changethispassword
composer run dev        # server, queue worker, log viewer and Vite together
```

Any local PHP environment works; the panel is plain Laravel with an SQLite database. A queue worker must run for anything that talks to a server for more than a moment (provisioning, deploys, app creation), and `php artisan schedule:work` runs the hourly update checks if you want to see them.

## Tests and checks

Run before every commit; CI runs the same things on every push and pull request.

```bash
php artisan test --exclude-testsuite=Browser   # panel, Pest
vendor/bin/pint --test                         # panel code style
bash server/tests/run.sh                       # CLI, against fixtures and fakes
shellcheck -x server/bin/mandal server/lib/*.sh server/lib/commands/*.sh server/lib/roles/*/*.sh server/setup.sh server/install.sh server/tests/run.sh
bash scripts/cli-reference.sh --check          # docs command table matches `mandal help`
bun run docs:build                             # the docs build
```

The browser tests need `bunx playwright install chromium` once and run with `composer run test`.

### Panel tests

Pest, under `tests/Feature`. SSH is replaced by `Tests\Support\FakeSshClient`, which answers each command with canned output (`respondTo`, `respondWithJson`) and records what ran (`ran`). `tests/Fixtures/inventory.json` is a full inventory the Filament pages are tested against. There is no test that needs a server.

### CLI tests

`server/tests/run.sh` is one Bash file of assertions. It sources the CLI with every `MANDAL_*` path pointed at `server/tests/fixtures/` or a temporary directory, and puts fake `apt-get`, `systemctl`, `useradd`, `docker` and friends on `PATH` that record what they were called with. Write commands are mostly exercised with `--dry-run`, which prints each step instead of running it, so the assertions compare printed steps. Add tests next to the command they cover; the file is grouped by command.

## Layout

| Path | What |
|---|---|
| `app/Mandal/` | `MandalClient` (runs CLI commands over SSH, parses JSON), `CliArchive` (the CLI tarball the panel ships and its hash), `CliUpdates`, `PanelReleases` |
| `app/Ssh/` | SSH and SFTP, host key pinning |
| `app/Jobs/` | One job per long task, all based on `RunMandalTask`, streaming output into a `Task` |
| `app/Filament/Resources/Servers/` | The server pages (`Pages/`), their actions (`Concerns/`) and the table |
| `server/bin/mandal` | CLI entry point: argument parsing, locking, command dispatch |
| `server/lib/*.sh` | Shared helpers (`core.sh`, `system.sh`), and the domains: `provision.sh`, `app.sh`, `deploy.sh`, `docker.sh`, `backup.sh`, `php.sh`, `updates.sh`, `logs.sh` |
| `server/lib/commands/*.sh` | One file per command group; each registers itself in `MANDAL_COMMANDS` |
| `server/lib/roles/<role>/<driver>.sh` | Database, cache and JavaScript drivers |
| `server/setup.sh`, `server/install.sh` | The two bootstrap scripts published as release assets |
| `docs/` | This site (VitePress). `bun run docs:dev` serves it locally |

## Conventions

- The server is the source of truth. The panel never stores what it can read from the server; if a page needs something new, add it to the CLI's JSON first.
- Read commands print JSON and nothing else. Write commands print one `+` line per step and support `--dry-run` through `run_cmd` and `write_file`.
- Secrets travel as uploaded files (`runWithUpload` in the panel, `--from=` in the CLI), never as arguments.
- Configuration goes in drop-in files; existing files are backed up with `backup_file` before being changed.
- A panel change that needs a new CLI command also bumps nothing: the CLI is versioned with the panel, and the build hash makes every server update to the panel's copy. Keep old CLI output readable by the panel for one release where that is cheap (the Firewall page's "does not report Docker yet" message is an example).
- Commit messages are a plain sentence describing the change. Keep commits atomic; a feature lands with its tests and docs.

## Writing docs

Pages are Markdown under `docs/`, with the sidebar in `docs/.vitepress/config.ts`. `bun run docs:dev` serves the site with hot reload. The command table on the CLI page is generated; after changing a command's summary, run `bash scripts/cli-reference.sh` and commit the result. Pushing to `main` deploys the site to GitHub Pages through `.github/workflows/docs.yml`.
