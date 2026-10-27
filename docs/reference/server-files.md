# Files changed on the server

What each task writes, links or removes on a managed server. Paths are the defaults; the test suite moves them with `MANDAL_*` variables. Configuration files Mandal writes (nginx sites, PHP pools, Supervisor programs, drop-ins, cron files) carry a `# managed-by: mandal` line; manifests, `.env` files and keys do not. A file it has to change but did not create is copied to `/var/backups/mandal/config` first.

Mandal's own state lives in two places:

| Path | Contents |
|---|---|
| `/etc/mandal/server.conf` | Role drivers, SSH port, when and by which version the server was provisioned |
| `/etc/mandal/apps/<app>.conf` | One manifest per app: type, domain, aliases, PHP version, repository, branch, database, proxy port and so on |
| `/etc/mandal/backups.conf`, `/etc/mandal/rclone.conf` | Backup schedule and the S3 remote (root only) |
| `/var/log/mandal/actions.log` | Every write command, with time, user and panel |
| `/var/log/mandal/deploys/<app>/<timestamp>.log` | Full output of each deployment |
| `/var/log/mandal/backups.log` | Output of scheduled backup runs |
| `/var/cache/mandal/` | Cached component release lookups (six hours) |
| `/var/backups/mandal/config/` | Copies of configuration files before Mandal changed them |
| `/var/backups/mandal/db/` | Database dumps |

## setup.sh

Run once per server from the panel's **Setup command**. Idempotent.

| Action | Path |
|---|---|
| Installs if missing | `jq`, `git`, `tar` packages |
| Creates the user | `/home/mandal` (`useradd --create-home`, shell `/bin/bash`) |
| Adds the panel's key | `/home/mandal/.ssh/authorized_keys` (appended once; `0700` directory, `0600` file) |
| Installs the CLI | `/opt/mandal/` (`bin/`, `lib/`, `VERSION`, root-owned; the previous copy is swapped out through `/opt/mandal.old`) |
| Links the command | `/usr/local/bin/mandal` |
| Allows sudo | `/etc/sudoers.d/mandal`: `mandal ALL=(root) NOPASSWD: /opt/mandal/bin/mandal` (checked with `visudo -c`) |

## provision

`mandal provision`, from the **Provision** action. Additive and idempotent. `--check` changes nothing; `--dry-run` prints every step instead of running it, but still creates `/var/log/mandal` and records any `--database=`, `--cache=` or `--js=` choice in `/etc/mandal/server.conf`.

| Step | Path or action |
|---|---|
| Base packages | `ca-certificates curl gnupg git unzip tar jq acl zstd rclone cron openssh-server` |
| Clock | `timedatectl set-timezone UTC`, NTP on |
| Swap | `/swapfile` (2 GiB, `0600`) and a line in `/etc/fstab`, only when no swap exists |
| Kernel | `/etc/sysctl.d/60-mandal.conf` |
| nginx | `nginx` package; `/etc/nginx/conf.d/mandal.conf` (global settings); `/etc/nginx/sites-available/mandal-default` linked into `sites-enabled` as the catch-all server, unless the stock `default` site is enabled and has been edited, in which case it is left as the catch-all |
| PHP | The ondrej PPA through `add-apt-repository ppa:ondrej/php` when it has packages for the release; `php<version>-*` packages; `/etc/php/<version>/<sapi>/conf.d/99-mandal.ini`; `update-alternatives` for `php`, `phar`, `phpize`, `php-config` |
| Composer | `/usr/local/bin/composer`, installer verified against its published signature |
| Database | `mariadb-server`, `mariadb-client`; `/etc/mysql/mariadb.conf.d/60-mandal.cnf` (`bind-address = 127.0.0.1`); root switched to Unix socket auth, anonymous users and the `test` database removed |
| Cache | Dragonfly `.deb` from its GitHub releases; `/etc/dragonfly/dragonfly.conf` with a generated password; the password also in `/etc/mandal/cache.conf` (`0600`) for app `.env` files |
| JavaScript | `/usr/local/bin/bun`, `/usr/local/bin/bunx` from Bun's GitHub releases |
| Supervisor | `supervisor` package, service enabled |
| Certbot | `certbot`, `python3-certbot-nginx`; `certbot.timer` enabled for renewals |
| Docker | Docker's apt source (`/etc/apt/keyrings/docker.asc`, `/etc/apt/sources.list.d/docker.sources`); `docker-ce`, `docker-ce-cli`, `containerd.io`, buildx and compose plugins; `mandal` user added to the `docker` group |
| Log rotation | `/etc/logrotate.d/mandal` |
| fail2ban | `fail2ban` package; `/etc/fail2ban/jail.d/mandal.local` |
| Unattended upgrades | `unattended-upgrades` package; `/etc/apt/apt.conf.d/52-mandal` (security, PHP and Docker origins, no automatic reboot) |
| Firewall | `ufw` package; defaults deny incoming and allow outgoing; rules for the SSH port, 80 and 443; ufw enabled and its unit started |
| SSH hardening | `/etc/ssh/sshd_config.d/10-mandal.conf` (`PermitRootLogin no`, `PasswordAuthentication no`, `PubkeyAuthentication yes`); `sshd -t` before reload. Skipped with `--skip-ssh-hardening` |
| Record | `/etc/mandal/server.conf`; `/etc/mandal/apps/` and `/var/log/mandal/` created |

## app:create

A Laravel app named `<app>` with PHP `<v>`.

| Action | Path |
|---|---|
| System user | `/home/<app>` (`0750`), `useradd --user-group`, shell `/bin/bash` |
| Directories | `releases/`, `shared/`, `shared/storage/{app,app/public,framework,framework/cache,framework/sessions,framework/views,logs}`, `tmp/`, `.local/bin/`, `.ssh/` (`0700`) |
| ACLs | `www-data` may traverse the home and read `releases/` and `shared/storage/`, nothing else |
| Deploy key | `/home/<app>/.ssh/id_ed25519` and `.pub` (`ssh-keygen` as the app user); GitHub's host keys appended to `/home/<app>/.ssh/known_hosts` |
| PHP | `/home/<app>/.local/bin/php` linked to `/usr/bin/php<v>`; pool `/etc/php/<v>/fpm/pool.d/<app>.conf` listening on `/run/php/php<v>-fpm-<app>.sock`, slow log `/var/log/php<v>-fpm-<app>-slow.log`; PHP-FPM reloaded |
| nginx | `/etc/nginx/sites-available/<app>.conf` linked into `sites-enabled`; logs `/var/log/nginx/<app>-access.log` and `<app>-error.log`; `nginx -t` then reload |
| Database | Database and user named after the app with hyphens replaced by underscores (`my-shop` gives `my_shop`@`localhost`), with a generated password. Skipped with `--no-database` |
| Environment | `/home/<app>/shared/.env` (`0600`, app user): `APP_KEY`, `APP_URL`, database and cache credentials, with cache, queue and sessions on the cache driver |
| Deploy script | `/home/<app>/deploy.sh` (`0750`, app user) |
| Scheduler | The app user's crontab: `* * * * * php<v> /home/<app>/current/artisan schedule:run` |
| Manifest | `/etc/mandal/apps/<app>.conf` |

With a GitHub token in the panel, the deploy key is also added to the repository.

## app:deploy and app:rollback

| Action | Path |
|---|---|
| Clone | `/home/<app>/releases/<timestamp>/`, as the app user, `git clone --branch <branch> --depth 1` with the deploy key (or over HTTPS) |
| Shared links | `releases/<timestamp>/storage` to `shared/storage`; `releases/<timestamp>/.env` to `shared/.env` |
| Build | `deploy.sh` runs inside the release as the app user |
| Record | `releases/<timestamp>/.mandal-release` (branch, commit, time) |
| Switch | `/home/<app>/current` repointed atomically (`current.new` then rename) |
| Reload | PHP-FPM for the app's version reloaded; `artisan queue:restart` run from the new release, plus `horizon:terminate` and `reverb:restart` when those packages are installed, so workers pick up the new code |
| Prune | Oldest releases removed down to `KEEP_RELEASES` (5 by default, 3 for the panel app) |
| Log | `/var/log/mandal/deploys/<app>/<timestamp>.log` |

A failed deploy removes `releases/<timestamp>` and never touches `current`. Rollback repoints `current`, reloads PHP-FPM and restarts the workers the same way; it does not run `deploy.sh`.

## app:configure

Rewrites the manifest's `REPO`, `BRANCH` or `KEEP_RELEASES`. For a proxied Docker app, `--port=` changes the `proxy_pass` line in `/etc/nginx/sites-available/<app>.conf` (backed up first), updates `PORT` in the manifest and reloads nginx.

## app:env

Replaces `/home/<app>/shared/.env` (Laravel) or `/srv/docker/<app>/.env` (Docker) from an uploaded file; mode and owner are kept. Nothing is restarted: redeploy the app (its `deploy.sh` runs `artisan optimize`, which caches the configuration) or restart the workers for the change to reach running processes, and recreate a Docker app's containers with **Restart**.

## app:ssl

Runs `certbot --nginx --redirect --keep-until-expiring --expand` for the app's domain and aliases after checking they resolve to the server. Certbot edits `/etc/nginx/sites-available/<app>.conf` (certificate lines and the HTTPS redirect) and stores certificates under `/etc/letsencrypt/live/<domain>/`. The site is not rewritten by Mandal after creation (only the `proxy_pass` line of a Docker app is edited in place), so Certbot's lines stay. Renewal runs from `certbot.timer`.

## worker:add, worker:remove, worker:restart

| Action | Path |
|---|---|
| Program | `/etc/supervisor/conf.d/<app>-<worker>.conf`, running `php<v> artisan <command>` as the app user with the chosen number of processes |
| Log | `/var/log/supervisor/<app>-<worker>.log` |
| Apply | `supervisorctl reread` and `update` after add and remove (remove stops the program first); `supervisorctl restart <app>-<worker>:*` for each of the app's programs on restart |

## app:delete

Removes, in order, the nginx site, the PHP-FPM pool, every Supervisor program of the app, the database and its user (unless `--keep-database`), the crontab, the user and `/home/<app>` (unless `--keep-files`, which keeps the home and only deletes the user), the manifest. With a GitHub token, the deploy key and any webhook are removed from the repository.

## Docker apps

`app:create --type=docker`:

| Action | Path |
|---|---|
| Project | `/srv/docker/<app>/` (`0750`, root) |
| Compose file | `/srv/docker/<app>/compose.yml` (`0640`), checked with `docker compose config` before anything starts; the directory is removed again if the check fails |
| Environment | `/srv/docker/<app>/.env` (`0600`) |
| Containers | `docker compose --project-name <app> pull`, then `up -d --remove-orphans` |
| nginx (with proxy) | `/etc/nginx/sites-available/<app>.conf` linked into `sites-enabled`, proxying to `127.0.0.1:<port>`; `/etc/nginx/conf.d/mandal-upgrade.conf` (websocket upgrade map) written once |
| Manifest | `/etc/mandal/apps/<app>.conf` with `TYPE=docker`, `PROXY`, `PORT` |

| Command | Changes |
|---|---|
| `app:compose --from=` | Replaces `compose.yml` and recreates the containers; the previous file is restored when the new one fails the check. A moved loopback port updates the nginx site when unambiguous |
| `app:deploy` | `docker compose pull`, then `up -d --remove-orphans` |
| `app:control` | `docker compose up -d`, `stop` or `restart` |
| `app:delete` | `docker compose down --remove-orphans`, with `--volumes` unless `--keep-files`; the nginx site and `/srv/docker/<app>` removed |

No system user is created for a Docker app, and no firewall rules are written: Docker publishes ports itself.

## Databases

| Command | Changes |
|---|---|
| `db:create`, `db:delete` | `CREATE DATABASE` / `DROP DATABASE` through the Unix socket as root |
| `db:user:create`, `db:user:update`, `db:user:delete` | `CREATE USER '<user>'@'localhost'`, `ALTER USER ... IDENTIFIED BY` (password read from an uploaded file), `DROP USER` |
| `db:access` | `GRANT ALL ON <db>.*` and `REVOKE` per user |

Nothing is written outside MariaDB. Databases and users that belong to an app are refused.

## Backups

| Command | Changes |
|---|---|
| `backup:configure` | `/etc/mandal/backups.conf` (schedule, retention, bucket); `/etc/mandal/rclone.conf` (`0600`, the S3 credentials); `/etc/cron.d/mandal-backups` running `mandal backup:run` on the schedule as root |
| `backup:run` | `/var/backups/mandal/db/<database>/<database>-<timestamp>.sql.zst` (`0600`); the panel's SQLite copied to `/var/backups/mandal/db/<app>-sqlite/`; upload to the remote; local and remote copies pruned to the retention; `/var/log/mandal/backups.log` appended. Lock `/run/lock/mandal-backup.lock` |
| `backup:restore` | A new database `<database>_restore_<time of the restore>` (or `--into=`), filled from the dump, with the original database's user granted all privileges on it. A backup that only exists remotely is downloaded first |

## Firewall

`firewall:allow` runs `ufw allow <port>/<proto>` (with `from <cidr>` when given) and `firewall:delete` runs `ufw --force delete <number>`. ufw keeps its state in `/etc/ufw/user.rules` and `user6.rules`. The SSH rule, and the HTTP and HTTPS rules on a server hosting the panel, are refused.

## PHP versions

| Command | Changes |
|---|---|
| `php:install` | `php<v>-*` packages; `/etc/php/<v>/{cli,fpm}/conf.d/99-mandal.ini`; `php<v>-fpm` enabled |
| `php:default` | `update-alternatives --set` for `php`, `phar`, `phar.phar`, `phpize`, `php-config` |
| `php:remove` | The version's packages purged; refused while an app uses it |

## Updates

| Command | Changes |
|---|---|
| `updates:apply` | `apt-get` upgrade of security updates, everything, or one package, with options that keep existing configuration files; nginx (when `nginx -t` passes) and every installed PHP-FPM reloaded afterwards |
| `component:update` | Dragonfly: new `.deb` installed, service restarted. Bun: new binary in `/usr/local/bin`. Composer: `composer self-update`. Docker: its packages upgraded |
| `cli:update` | `/opt/mandal` replaced by the uploaded archive (checked to parse first), old copy removed via `/opt/mandal.old` |
| `reboot` | `systemctl reboot` through a transient systemd timer five seconds later, so the SSH session ends cleanly first |

`updates:status` writes only to `/var/cache/mandal/`.

## Keys and the panel

| Command | Changes |
|---|---|
| `key:add` | A labelled line appended to `/home/mandal/.ssh/authorized_keys` |
| `key:remove` | That line removed; the last key is refused |
| `panel:install` | Everything `app:create` does for `mandal-panel`, except the MariaDB database: `shared/.env` uses SQLite at `shared/database.sqlite` (created empty, `0600`) and the database queue; `/etc/supervisor/conf.d/mandal-panel-queue.conf` runs `queue:work`; the manifest carries `PANEL=true` |
| `panel:update` | Downloads the release, then `cli:update`, `app:configure --branch=<tag>` and `app:deploy` for the panel app |

## install.sh

Runs the steps above in order on a fresh server: `setup.sh`'s CLI install (without a panel key), `provision`, `panel:install`, `app:deploy`, `app:ssl`, `php artisan db:seed` as the panel user, `php artisan mandal:register-host` and `key:add` for the panel's own key. When a panel is already installed it delegates to `panel:update` instead.
