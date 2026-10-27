# Mandal

[![CI](https://github.com/ReyArlena/mandal/actions/workflows/ci.yml/badge.svg)](https://github.com/ReyArlena/mandal/actions/workflows/ci.yml)
[![Docs](https://github.com/ReyArlena/mandal/actions/workflows/docs.yml/badge.svg)](https://reyarlena.github.io/mandal/)
[![Release](https://img.shields.io/github/v/release/ReyArlena/mandal?sort=semver)](https://github.com/ReyArlena/mandal/releases/latest)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A self-hosted panel for managing Laravel and Docker servers. Mandal connects to your servers over SSH, provisions a native stack on Ubuntu 26.04 (nginx, PHP, MariaDB, Dragonfly, Bun, Supervisor, Certbot, Docker, ufw, fail2ban) and manages apps, deployments, databases, backups, updates and logs. The server is always the source of truth: Mandal reads live state through its own CLI rather than keeping a copy.

**Documentation: [reyarlena.github.io/mandal](https://reyarlena.github.io/mandal/)**

## Install

Point a domain at a fresh Ubuntu 26.04 server and run as root:

```bash
curl -fsSL https://github.com/ReyArlena/mandal/releases/latest/download/install.sh | sudo bash -s -- --domain=panel.example.com
```

The script installs the `mandal` CLI, provisions the server, deploys the panel from the release, requests a certificate, creates the first user and registers the server in its own panel. Run it again to resume or update. See [Hosting the panel](https://reyarlena.github.io/mandal/guide/self-hosting).

To run the panel on your own machine instead (PHP 8.3+, Composer, Bun):

```bash
git clone https://github.com/ReyArlena/mandal.git && cd mandal
composer run setup && php artisan db:seed
composer run dev
```

Sign in as `admin@admin.com` / `changethispassword`, set up the authenticator app, then change both. See [Getting started](https://reyarlena.github.io/mandal/guide/getting-started).

## How it works

- Every managed server runs `mandal`, a Bash CLI from `server/` installed to `/opt/mandal`. A one-line setup command creates a `mandal` user that may run only that CLI through sudo. Servers reach GitHub, never the panel.
- Read commands return JSON; write commands run as queued tasks with live output. Provisioning is additive and idempotent, and keeps everything it did not create.
- Laravel apps get their own user, PHP-FPM pool, nginx site, database, `.env`, deploy key and `deploy.sh`, with zero-downtime deploys, roll back, Supervisor workers and Let's Encrypt. Docker apps run from a compose file, optionally behind nginx.
- Several panels can manage one server, and a panel can manage the server it runs on.

The [Server CLI reference](https://reyarlena.github.io/mandal/reference/cli) lists every command, and [Files changed on the server](https://reyarlena.github.io/mandal/reference/server-files) lists what each task writes.

## Development

```bash
composer run dev                               # server, queue worker, logs and Vite
php artisan test --exclude-testsuite=Browser   # panel tests
bash server/tests/run.sh                       # CLI tests (bash and jq)
bun run docs:dev                               # this documentation
```

[Contributing](https://reyarlena.github.io/mandal/contributing/) covers the layout, conventions and end-to-end testing with Incus. Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## License

Mandal is open-source software licensed under the [MIT license](LICENSE).
