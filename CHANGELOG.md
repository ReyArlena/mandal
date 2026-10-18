# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Laravel 13 application with a Filament 5 panel served from the site root.
- Required app-based multi-factor authentication with recovery codes for every account.
- Seeded initial administrator account for first login.
- Pest test suite, including browser tests for the login flow.
- `mandal` server CLI with `inventory`, `app:list`, `provision --check` and `version`, plus role drivers for MariaDB, Dragonfly and Bun.
- CLI test suite running against fixtures and stand-in commands.
- Servers: create, edit and delete servers, each with its own Ed25519 key pair (private key encrypted at rest).
- SSH connections pin the server's host key after the fingerprint is confirmed and refuse to connect if it changes.
- "Test connection" action on a server, and a server list showing connection status.
- One-line setup command for new servers: a token-protected script that creates the SSH user, installs the panel's key and the CLI, and limits sudo to the CLI.
- Inventory page per server showing system, services, PHP, sites, databases, workers, certificates, firewall, Docker and apps, read live from the server.
- Provisioning from the panel: a "Provision" action queues `mandal provision` with the chosen drivers, and a Tasks tab per server shows each run with live output.
- Apps: `mandal app:create` and `app:delete` set up a Laravel app with its own system user, PHP-FPM pool, nginx site (www redirect modes and aliases), database, production `.env`, scheduler crontab, deploy key and deploy script. An Apps tab in the panel lists apps and queues these as tasks.
- Several panels per server: `mandal key:list/add/remove` manage the labelled SSH keys allowed to run the CLI, and a "Grant access" action adds another panel's key. The panel's own SQLite database is included in backups when the server hosts the panel.
- Docker apps: `app:create --type=docker --image= --port=` runs an image through a compose project bound to 127.0.0.1 with nginx in front (websockets included); deploying pulls and recreates, deleting removes the project and site. The panel's New app form offers the Docker type, and the app page shows the containers.
- Self-hosting: `mandal panel:install` creates the panel as an app flagged as such (SQLite under `shared/`, database queue, a queue worker). That app cannot be deleted from the panel, the HTTP and HTTPS firewall rules are protected while it exists, and rebooting warns that the panel goes offline.
- GitHub integration: a Settings page holds the panel's public URL and a fine-grained personal access token (stored encrypted, checked against GitHub on entry, expiry shown). With a token, deploy keys are added to the repository when an app is created and removed when it is deleted.
- Auto deploy on push: enabling it creates a GitHub webhook, pings it and keeps it only if GitHub reached the panel; pushes to the app's branch queue a deploy. Deliveries are verified with `X-Hub-Signature-256`, handled once, and refused while another task runs.
- Firewall: `mandal firewall:list/allow/delete` and a Firewall tab (the SSH rule can never be deleted). PHP versions: `mandal php:available/list/install/default/remove` and a PHP tab.
- Updates: `mandal updates:status/apply`, `component:update` and `reboot`, with an Updates tab showing pending security and other packages, kernel and reboot state, component versions against their latest releases, automatic-update results and package history, and buttons to install updates, update components or the CLI, and reboot.
- Only one task runs per server at a time; the panel says so instead of dropping a second one.
- Logs: `mandal logs:sources` and `logs:read` expose service journals, the kernel and auth journals, nginx, PHP-FPM, fail2ban, apt and mandal logs, and per app the nginx logs, Laravel log (split into entries with stack traces), PHP slow log, worker logs and deployments. A Logs tab in the panel filters, searches and tails them live.
- Database backups: `mandal backup:configure/run/list/status/restore` with a cron schedule, separate local and remote retention, optional upload to any S3-compatible bucket through rclone, and restores into a fresh database. A Backups tab in the panel manages all of it.
- App page in the panel with details, deploy key, releases (roll back to any), `.env` editor, SSL request and Supervisor workers (add, remove, restart), backed by `mandal worker:add/remove/restart/list`, `app:ssl`, `app:env` and `app:releases`.
- `mandal cli:update` and an "Update CLI" action in the panel; the inventory reports a build hash so out-of-date servers are spotted.
- Deployments: `mandal app:deploy` clones the repository with the app's deploy key into a timestamped release, links shared storage and `.env`, runs the app's `deploy.sh`, switches `current`, reloads PHP-FPM, restarts workers and prunes old releases; `app:rollback` switches back; `app:releases` lists releases; `app:configure` changes the repository, branch or releases to keep. Deploy and Roll back actions in the panel.
- `mandal provision`: installs and hardens the stack on Ubuntu 24.04 (nginx, newest PHP with all required extensions, Composer, MariaDB, Dragonfly, Bun, Supervisor, Certbot, Docker, ufw, fail2ban, unattended upgrades, swap, SSH hardening) through drop-in files, keeping existing configuration. Supports `--dry-run`, `--php=`, driver choices and `--skip-ssh-hardening`.
