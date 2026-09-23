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
- `mandal provision`: installs and hardens the stack on Ubuntu 24.04 (nginx, newest PHP with all required extensions, Composer, MariaDB, Dragonfly, Bun, Supervisor, Certbot, Docker, ufw, fail2ban, unattended upgrades, swap, SSH hardening) through drop-in files, keeping existing configuration. Supports `--dry-run`, `--php=`, driver choices and `--skip-ssh-hardening`.
