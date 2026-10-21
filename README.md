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

## Hosting the panel on a managed server

The panel can live on one of the servers it manages. Provision the server from a local copy of the panel first, then on the server run:

```bash
sudo mandal panel:install --domain=panel.example.com --repo=<owner>/<repo>   # add --https for a public repository
sudo mandal app:deploy --name=mandal-panel
sudo mandal app:ssl --name=mandal-panel
sudo -u mandal-panel -H php /home/mandal-panel/current/artisan db:seed      # first user
```

The panel app is flagged on the server: it cannot be deleted from the panel, the HTTP and HTTPS firewall rules stay protected, and the panel's SQLite database lives under `shared/` so deploys keep it. Add the server to the hosted panel afterwards with "Connect to an existing Mandal server" so it can manage itself. Keep a backup of `shared/database.sqlite` together with the `APP_KEY` from `shared/.env`.

## Running in production

Mandal holds credentials for every server it manages, so treat it as the most sensitive application you run.

- Serve it over HTTPS only.
- In `.env`, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the public address and `SESSION_SECURE_COOKIE=true`.
- Run `php artisan optimize` after each deployment.
- Run a queue worker (`php artisan queue:work`) under a process manager such as Supervisor. Provisioning, deployments and other long-running tasks are queued.
- Add `* * * * * php artisan schedule:run` to the crontab of the user that owns the application.
- Back up `database/database.sqlite` together with the `APP_KEY` from `.env`. Encrypted values in the database cannot be read without the key.

## Servers

The server switcher next to the logo moves between servers and keeps you on the same page. Inside a server, the sidebar lists its pages (overview, Apps, Inventory, PHP, Firewall, Backups, Logs, Updates, Tasks, Edit); elsewhere it lists the servers. Panel settings and your profile are in the user menu.

Each server gets its own SSH key pair when it is added; the private key is stored encrypted with your `APP_KEY`. The first time Mandal connects, it shows the server's host key fingerprint for you to confirm, then pins it. If that key ever changes, Mandal refuses to connect until you accept the new one.

### Adding a new server

1. Add the server in the panel with its address. Mandal supports Ubuntu 26.04 LTS.
2. Open the server and choose **Setup command**. Run the command shown on the server as root. It creates the `mandal` user, installs the panel's public key and the `mandal` CLI, and allows that user to run only the CLI through sudo. Nothing else on the server is touched.
3. Choose **Test connection**, check the host key fingerprint it shows against `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server, and confirm.

4. Choose **Provision**. Mandal installs and hardens the stack in the background (nginx, PHP, Composer, MariaDB, Dragonfly, Bun, Supervisor, Certbot, Docker, firewall, fail2ban, automatic security updates) and shows the output live under the server's **Tasks** page. A queue worker must be running for this (`php artisan queue:work`, or `composer run dev` locally).

### Several panels, one server

Everything Mandal knows about a server lives on the server, so any Mandal panel with an accepted key can manage it. To let a second panel in, add that panel's public key (shown on its server page) with **Grant access** on the first panel, or on the server with `sudo mandal key:add --name="Laptop panel" --key="ssh-ed25519 ..."`. Keys are labelled and can be removed again with `mandal key:remove`; the last key can never be removed.

### Apps

The **Apps** page creates Laravel apps on a provisioned server. Each app gets its own system user and home (`/home/<app>`), a PHP-FPM pool running as that user, an nginx site with per-app logs, a database and user, a production `.env` under `shared/`, the scheduler crontab, a deploy key and an editable `deploy.sh`. Add the deploy key to the GitHub repository (read-only is enough), then use **Deploy**: the branch is cloned into `releases/<timestamp>`, `deploy.sh` runs (Composer, Bun build, migrations, `optimize`), and the `current` symlink switches only when it succeeds. **Roll back** returns to the previous release, or to any release listed on the app's page.

Each app's page also edits its `.env`, requests a Let's Encrypt certificate (after checking DNS points at the server) and manages Supervisor **workers**: `queue:work`, Horizon or Reverb processes that run as the app user and restart after every deploy.

When the panel's copy of the `mandal` CLI is newer than a server's, the server page offers **Update CLI**, which uploads and installs it over SSH.

### GitHub and automatic deploys

Under **Settings**, connect a fine-grained GitHub personal access token with repository permissions *Administration (read and write)*, *Webhooks (read and write)* and *Contents (read)* for the repositories you deploy. Mandal checks the token, stores it encrypted and shows when it expires. From then on, creating an app adds its deploy key to the repository and deleting the app removes it.

To deploy on every push, set the panel's **Public URL** in Settings (it must be reachable from the internet over HTTPS), then use **Enable auto deploy** on the app. Mandal adds a webhook to the repository and asks GitHub to send a test delivery; if that delivery does not reach the panel, the webhook is removed again and the reason is shown. Only pushes to the app's configured branch deploy. Manual deploys always work, so a panel that runs on your own machine simply does without auto deploy.

### Docker apps

Choose **Docker image** in the New app form for anything that is not Laravel: give it an image, the port the container listens on and any environment variables. Mandal writes a compose project to `/srv/docker/<app>`, binds the container to `127.0.0.1` on a port it picks (Docker publishes straight past the firewall, so nothing is exposed directly) and puts an nginx site in front with websocket support. **Pull and restart** fetches the latest image; SSL and the environment editor work as for Laravel apps.

### Backups

The **Backups** page sets a nightly schedule for dumping every database on the server (`mariadb-dump` compressed with zstd), how many copies to keep locally and remotely, and optionally an S3-compatible bucket (AWS S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO, Hetzner and others) to upload to. Credentials are written to `/etc/mandal/rclone.conf` on the server, readable only by root; the panel never stores them. Any backup can be restored into a fresh database next to the live one for checking.

### PHP versions and the firewall

The **PHP** page lists installed versions, which apps use each one and the CLI default, and installs any version the package source offers with every extension Laravel needs. A version cannot be removed while an app uses it. The **Firewall** page shows the ufw rules, allows a port (optionally from one address only) and deletes rules, except the SSH rule.

### Updates

Ubuntu security updates and PHP patch releases install themselves (unattended-upgrades, no automatic reboot). The **Updates** page shows everything else: pending packages split into security and other, held packages, whether a reboot is needed and why, the running kernel against the newest installed one, the last automatic update run, each component's installed and latest version (Dragonfly, Bun, Composer and Docker are checked against their releases) and recent package changes. Install security updates, all updates or a single package, update a component or the CLI, and reboot, all from the page. Existing configuration files are always kept.

### Logs

The **Logs** page reads logs straight from the server: service journals (nginx, PHP-FPM, MariaDB, Dragonfly, Supervisor, Docker, fail2ban, SSH), the kernel and authentication journals, global nginx and PHP-FPM logs, fail2ban, package updates, and for each app its nginx access and error logs, Laravel log (one entry per message, stack traces attached, filterable by level), PHP slow log, worker logs and the latest deployment (Docker apps: nginx logs and the container output). Filter by text, limit the lines, and switch on **Tail** to follow a log live. Sources are addressed by name, never by path, so the panel can only read the logs listed.

Once connected, the server's **Inventory** page shows what is on it (services, PHP versions and pools, nginx sites, databases, Supervisor programs, certificates, firewall rules, Docker, and apps managed by Mandal). Nothing is stored in the panel; it is read from the server on each visit and cached for a few seconds.

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
