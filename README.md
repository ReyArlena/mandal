# Mandal

A self-hosted panel for managing Laravel servers. Mandal connects to your servers over SSH, provisions a native LEMP stack, and manages apps, databases, deployments, backups and updates. The server is always the source of truth: Mandal reads live state rather than keeping its own copy.

## Requirements

- PHP 8.3 or newer, with the `sqlite3`, `mbstring`, `intl`, `curl` and `zip` extensions
- Composer
- Bun

## First-time setup

```bash
git clone <repository-url> mandal
cd mandal
composer run setup
php artisan db:seed
```

`composer run setup` installs dependencies, creates `.env`, generates the application key, runs migrations and builds the front-end assets. The seed step creates the initial administrator account.

Serve the application (for example with `php artisan serve`, or your usual local environment), then sign in:

| Field | Value |
|---|---|
| Email | `admin@admin.com` |
| Password | `changethispassword` |

On first sign-in you will be asked to set up an authenticator app. Multi-factor authentication is required for every account. Once in, open your profile from the user menu and change the email address and password straight away.

There is no public registration page. Additional users are created from within the panel.

## Running in production

Mandal holds credentials for every server it manages, so treat it as the most sensitive application you run.

- Serve it over HTTPS only.
- In `.env`, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the public address and `SESSION_SECURE_COOKIE=true`.
- Run `php artisan optimize` after each deployment.
- Run a queue worker (`php artisan queue:work`) under a process manager such as Supervisor. Provisioning, deployments and other long-running tasks are queued.
- Add `* * * * * php artisan schedule:run` to the crontab of the user that owns the application.
- Back up `database/database.sqlite` together with the `APP_KEY` from `.env`. Encrypted values in the database cannot be read without the key.

## Servers

Each server gets its own SSH key pair when it is added; the private key is stored encrypted with your `APP_KEY`. The first time Mandal connects, it shows the server's host key fingerprint for you to confirm, then pins it. If that key ever changes, Mandal refuses to connect until you accept the new one.

### Adding a new server

1. Add the server in the panel with its address.
2. Open the server and choose **Setup command**. Run the command shown on the server as root. It creates the `mandal` user, installs the panel's public key and the `mandal` CLI, and allows that user to run only the CLI through sudo. Nothing else on the server is touched.
3. Choose **Test connection**, check the host key fingerprint it shows against `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server, and confirm.

4. Choose **Provision**. Mandal installs and hardens the stack in the background (nginx, PHP, Composer, MariaDB, Dragonfly, Bun, Supervisor, Certbot, Docker, firewall, fail2ban, automatic security updates) and shows the output live under the server's **Tasks** tab. A queue worker must be running for this (`php artisan queue:work`, or `composer run dev` locally).

### Apps

The **Apps** tab creates Laravel apps on a provisioned server. Each app gets its own system user and home (`/home/<app>`), a PHP-FPM pool running as that user, an nginx site with per-app logs, a database and user, a production `.env` under `shared/`, the scheduler crontab, a deploy key and an editable `deploy.sh`. Add the deploy key to the GitHub repository (read-only is enough), then use **Deploy**: the branch is cloned into `releases/<timestamp>`, `deploy.sh` runs (Composer, Bun build, migrations, `optimize`), and the `current` symlink switches only when it succeeds. **Roll back** returns to the previous release, or to any release listed on the app's page.

Each app's page also edits its `.env`, requests a Let's Encrypt certificate (after checking DNS points at the server) and manages Supervisor **workers**: `queue:work`, Horizon or Reverb processes that run as the app user and restart after every deploy.

When the panel's copy of the `mandal` CLI is newer than a server's, the server page offers **Update CLI**, which uploads and installs it over SSH.

### Backups

The **Backups** tab sets a nightly schedule for dumping every database on the server (`mariadb-dump` compressed with zstd), how many copies to keep locally and remotely, and optionally an S3-compatible bucket (AWS S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO, Hetzner and others) to upload to. Credentials are written to `/etc/mandal/rclone.conf` on the server, readable only by root; the panel never stores them. Any backup can be restored into a fresh database next to the live one for checking.

Once connected, the server's **Inventory** tab shows what is on it (services, PHP versions and pools, nginx sites, databases, Supervisor programs, certificates, firewall rules, Docker, and apps managed by Mandal). Nothing is stored in the panel; it is read from the server on each visit and cached for a few seconds.

The setup link expires after 24 hours and stops working after the first successful connection. The server needs to reach the panel to download the script and the CLI, so a panel that only runs on your own machine cannot use the one-line command; open the script from the dialog instead.

## Server CLI

Every managed server runs `mandal`, a Bash tool found in `server/` of this repository and installed to `/opt/mandal` on the server. It is the only way the panel reads or changes a server, and it can be used by hand over SSH:

```bash
sudo mandal inventory           # everything on the server, as JSON
sudo mandal app:list            # apps and their manifests
sudo mandal provision --check   # what provisioning would add, and any conflicts
sudo mandal provision --dry-run # print every step without changing anything
sudo mandal provision           # install and harden the stack
mandal version
```

Read commands print JSON. Provisioning is idempotent and additive: it installs what is missing, puts its own settings in drop-in files (`sshd_config.d`, `jail.d`, `mariadb.conf.d`, `conf.d` and so on), backs up any file it has to change under `/var/backups/mandal/config`, validates nginx and sshd configuration before reloading, and never removes rules, sites, databases or users it did not create. SSH hardening (key-only logins, no root login) runs last, after the panel has already signed in with its key. Server-side state lives in standard locations (`/etc/nginx`, `/etc/php`, `/etc/supervisor` and so on), with Mandal's own metadata in `/etc/mandal`. Nothing is cached in the panel.

Components are split into roles (`database`, `cache`, `js`), each implemented by a driver in `server/lib/roles/`. The driver for each role is recorded in `/etc/mandal/server.conf`.

## Development

```bash
composer run dev
```

This starts the development server, queue worker, log viewer and Vite together.

### Tests

```bash
bunx playwright install chromium   # once, for the browser tests
composer run test                  # panel
bash server/tests/run.sh           # server CLI (needs bash and jq)
```

The CLI tests run against fixture directories and stand-in commands, so they work on any machine without touching the system.
