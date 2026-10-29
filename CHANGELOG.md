# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Deploys reset requests that were in flight, because the PHP-FPM reload after switching releases killed workers at once (`process_control_timeout` defaults to 0; the panel's own task page showed "Error while loading page" while deploying itself). Provisioning and `php:install` now write `pool.d/00-mandal-global.conf` with a 10 s timeout, and the inventory no longer lists that `[global]` drop-in as a pool.
- Deployed apps had their `APP_NAME` replaced by the app's slug (the panel showed "mandal-panel" instead of "Mandal"): the deploy environment exported `APP_NAME` for `deploy.sh`, and `artisan optimize` cached it over the value in `.env`. The variable is now `MANDAL_APP`.
- `install.sh` failed at its last step on a fresh server because nothing had created the `mandal` SSH user: `key:add` now creates the user and its sudo rule when they are missing.
- The panel's queue worker stayed in Supervisor's FATAL state after `install.sh`, since it was added before the first release existed. Deploys now start any of the app's Supervisor processes that is not running.

### Changed

- Backup destinations (S3-compatible buckets) moved to **Settings**, stored encrypted in the panel and pushed to a server only when a backup there uses them (`backup:remote`, one rclone config per destination under `/etc/mandal/rclone.d/`). Database backups now have a local and a remote destination, each with its own schedule and copies to keep (`backup:configure --from=` with `ENABLED`, `LOCAL_*`, `REMOTE_*` keys; `backup:run --destination=local|remote|all`; the cron file carries one line per destination). A remote that cannot be reached no longer loses the backup: the copy stays local, the run is logged as `failed-remote`, and `backup:status` lists every app target with its last run so the panel can check a whole server in one call. Servers configured before this update keep their files but must save their backup settings again: the old `/etc/mandal/backups.conf` and `/etc/mandal/rclone.conf` are no longer read.
- Database backups are on by default: provisioning (and `panel:install`) writes a local nightly backup at 02:30 keeping seven copies, unless the file already exists.
- The `.env`, deploy script and compose file editors (and the compose and deploy script fields when creating an app) use Filament's code editor, with line numbers and YAML highlighting for compose files, instead of a plain text area.
- Pulling a Docker app's images asks "Pull and restart?" and describes what `docker compose pull` and `up -d` do, instead of the Laravel deploy wording.
- Adding a worker offers templates for a queue worker, Horizon, Reverb and `schedule:work` that fill in the form, and each worker on the app page has its own Restart button.
- The Settings page's Public URL and GitHub token buttons moved into their sections' headers, next to the new Backup destinations section.
- The sidebar and breadcrumbs are gone: a server's pages are tabs above the page title, and the servers list has none since its table shows every server. On small screens the server switcher moved from the sidebar drawer to the top bar, which drops the logo on phones to make room.
- The server overview and Inventory are one **Server** tab: connection details and the panel's public key on top, the inventory below, with Inventory's Refresh button next to the server's actions. The page title is the server's name, and the old `/servers/{id}/inventory` address is gone.

### Added

- App backups: a **Backups** card on every app page archives a Docker app's project directory or a Laravel app's `shared/` with tar and zstd, with excludes picked from a two-level tree with sizes or typed as patterns, optional container pausing while archiving, local and remote destinations with their own schedules, **Back up now**, and archives listed newest first with **Download** and **Delete**. CLI: `app:backup-tree`, `app:backup-configure`, `app:backup`, `app:backup-list`, `app:backup-delete`, `app:backup-get`; `app:delete` removes the settings, cron lines and archives.
- The panel checks every server hourly (`mandal:check-backups`) and raises a persistent notification for each failed scheduled backup run, once per run.
- `backup:delete` removes one database backup (local or remote) from the Backups page, and `backup:remote --remove --force` detaches a destination from every backup that used it.
- The deploy script can be edited from the panel: the create form starts with Mandal's default `deploy.sh`, the app page has a **Deploy script** editor with **Reset to default**, and the CLI gained `app:deploy-script` (print, `--from=`, `--reset`, `--default`). A syntax check with `bash -n` refuses a broken script.
- The create form explains where a new app's database password ends up (the app's `.env`, readable from the app page).

- Laravel 13 application with a Filament 5 panel served from the site root.
- Required app-based multi-factor authentication with recovery codes for every account.
- Seeded initial administrator account for first login.
- Pest test suite, including browser tests for the login flow.
- `mandal` server CLI with `inventory`, `app:list`, `provision --check` and `version`, plus role drivers for MariaDB, Dragonfly and Bun.
- CLI test suite running against fixtures and stand-in commands.
- Servers: create, edit and delete servers, each with its own Ed25519 key pair (private key encrypted at rest).
- SSH connections pin the server's host key after the fingerprint is confirmed and refuse to connect if it changes.
- "Test connection" action on a server, and a server list showing connection status.
- One-line setup command for new servers: `setup.sh` from the latest GitHub release creates the SSH user, installs the panel's key and the CLI, and limits sudo to the CLI. The server never has to reach the panel, and the script can be run again safely.
- Inventory page per server showing system, services, PHP, sites, databases, workers, certificates, firewall, Docker and apps, read live from the server.
- Provisioning from the panel: a "Provision" action queues `mandal provision` with the chosen drivers, and a Tasks page per server shows each run with live output.
- Apps: `mandal app:create` and `app:delete` set up a Laravel app with its own system user, PHP-FPM pool, nginx site (www redirect modes and aliases), database, production `.env`, scheduler crontab, deploy key and deploy script. An Apps page in the panel lists apps and queues these as tasks.
- Several panels per server: `mandal key:list/add/remove` manage the labelled SSH keys allowed to run the CLI, and a "Grant access" action adds another panel's key. The panel's own SQLite database is included in backups when the server hosts the panel.
- Docker apps: `app:create --type=docker --from=<compose file>` runs the compose file as a project under `/srv/docker/<app>`, checked with `docker compose config`; with `--proxy --port=` nginx serves a domain from a port a service publishes on 127.0.0.1 (websockets included), otherwise the project publishes its own ports and has no domain. `app:compose` reads or replaces the file and recreates the containers, keeping the previous file when the new one is invalid; `app:configure --port=` moves nginx to another published loopback port; `app:control --action=start|stop|restart` controls the containers; deploying pulls and recreates, deleting removes the project and site. The panel's New app form takes the compose file with an optional nginx proxy, the app page shows the published ports, containers and a compose editor, and the Firewall page lists the ports Docker publishes past ufw with a warning.
- Self-hosting: `mandal panel:install` creates the panel as an app flagged as such (SQLite under `shared/`, database queue, a queue worker). That app cannot be deleted from the panel, the HTTP and HTTPS firewall rules are protected while it exists, and rebooting warns that the panel goes offline.
- `install.sh` from a GitHub release installs the CLI, provisions the server, deploys the panel from the release tag, requests a certificate, creates the first user and registers the server in its own panel (`php artisan mandal:register-host`). Running it again resumes. `mandal panel:update` updates an installed panel to a release (CLI first, then the panel app), and the panel notifies users and shows the command on the hosting server's page when GitHub has a newer release.
- GitHub integration: a Settings page holds the panel's public URL and a fine-grained personal access token (stored encrypted, checked against GitHub on entry, expiry shown). With a token, deploy keys are added to the repository when an app is created and removed when it is deleted.
- Auto deploy on push: enabling it creates a GitHub webhook, pings it and keeps it only if GitHub reached the panel; pushes to the app's branch queue a deploy. Deliveries are verified with `X-Hub-Signature-256`, handled once, and refused while another task runs.
- Firewall: `mandal firewall:list/allow/delete` and a Firewall page (the SSH rule can never be deleted). PHP versions: `mandal php:available/list/install/default/remove` and a PHP page.
- Updates: `mandal updates:status/apply`, `component:update` and `reboot`, with an Updates page showing pending security and other packages, kernel and reboot state, component versions against their latest releases, automatic-update results and package history, and buttons to install updates, update components or the CLI, and reboot.
- Server switcher in the top bar that keeps the current page when switching. The sidebar lists the open server's pages, or the servers when none is open; Settings moved to the user menu.
- Only one task runs per server at a time; the panel says so instead of dropping a second one.
- Logs: `mandal logs:sources` and `logs:read` expose service journals, the kernel and auth journals, nginx, PHP-FPM, fail2ban, apt and mandal logs, and per app the nginx logs, Laravel log (split into entries with stack traces), PHP slow log, worker logs and deployments, or the container output for Docker apps. A Logs page in the panel filters, searches and tails them live.
- Databases: `mandal db:list/create/access/delete` and `db:user:create/update/delete` manage local MariaDB databases and users (passwords read from an uploaded file), refusing to delete what an app uses or to take an app's access to its own database. A Databases page in the panel, with Backups as its second tab.
- Database backups: `mandal backup:configure/run/list/status/restore` with a cron schedule, separate local and remote retention, optional upload to any S3-compatible bucket through rclone, and restores into a fresh database. A Backups page in the panel manages all of it.
- App page in the panel with details, deploy key, releases (roll back to any), `.env` editor, SSL request and Supervisor workers (add, remove, restart), backed by `mandal worker:add/remove/restart/list`, `app:ssl`, `app:env` and `app:releases`.
- `mandal cli:update` and an "Update CLI" action in the panel; the inventory reports a build hash so out-of-date servers are spotted. After the first successful connection test, the panel installs its own build when the release's differs. The panel records each server's build, flags out-of-date servers in the servers list, raises a persistent notification per user until the server is updated, and checks every connected server hourly (`mandal:check-updates`).
- Deployments: `mandal app:deploy` clones the repository with the app's deploy key into a timestamped release, links shared storage and `.env`, runs the app's `deploy.sh`, switches `current`, reloads PHP-FPM, restarts workers and prunes old releases; `app:rollback` switches back; `app:releases` lists releases; `app:configure` changes the repository, branch or releases to keep. Deploy and Roll back actions in the panel.
- Documentation site under `docs/` (VitePress), published to GitHub Pages on every push to `main`: guides for every page of the panel, the CLI reference with a generated command table (`scripts/cli-reference.sh`, checked in CI), a list of the files each task changes on the server, and contributing pages covering local setup, Incus testing and releasing.
- `mandal provision`: installs and hardens the stack on Ubuntu 26.04 (nginx, newest PHP with all required extensions from the ondrej PPA, or Ubuntu's own PHP while the PPA has no packages for the release, Composer, MariaDB, Dragonfly, Bun, Supervisor, Certbot, Docker, ufw, fail2ban, unattended upgrades, swap, SSH hardening) through drop-in files, keeping existing configuration. Supports `--dry-run`, `--php=`, driver choices and `--skip-ssh-hardening`.
