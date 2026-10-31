# Files changed on the server

What each task writes, links or removes on a managed server. Paths are the defaults; the test suite moves them with `MANDAL_*` variables. Configuration files Mandal writes (nginx sites, PHP pools, Supervisor programs, drop-ins, cron files) carry a `# managed-by: mandal` line; manifests, `.env` files and keys do not. A file it has to change but did not create is copied to `/var/backups/mandal/config` first.

Mandal's own state lives in two places:

| Path | Contents |
|---|---|
| `/etc/mandal/server.conf` | Role drivers, SSH port, when and by which version the server was provisioned |
| `/etc/mandal/apps/<app>.conf` | One manifest per app: type, domain, aliases, PHP version, repository, branch, database, proxy port and so on |
| `/etc/mandal/backups/databases.conf`, `/etc/mandal/backups/app-<app>.conf` | Backup targets: enabled, local and remote destination with schedule and copies to keep, remote subfolder; app targets add `PAUSE=` and an `app-<app>.exclude` file with one path or glob per line |
| `/etc/mandal/remotes/<slug>.conf` | A backup destination pushed from the panel's Settings: label, endpoint, region, bucket and path prefix (no secrets) |
| `/etc/mandal/rclone.d/<slug>.conf` | The rclone config for that destination, with its keys (root only, `0600`) |
| `/var/log/mandal/actions.log` | Every write command, with time, user and panel |
| `/var/log/mandal/deploys/<app>/<timestamp>.log` | Full output of each deployment |
| `/var/log/mandal/backups.log` | Output of scheduled backup runs |
| `/var/cache/mandal/` | Cached component release lookups (six hours) |
| `/var/backups/mandal/config/` | Copies of configuration files before Mandal changed them |
| `/var/backups/mandal/db/` | Database dumps |
| `/var/backups/mandal/apps/` | App archives |

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
| PHP | The ondrej PPA through `add-apt-repository ppa:ondrej/php` when it has packages for the release; `php<version>-*` packages; `/etc/php/<version>/<sapi>/conf.d/99-mandal.ini`; `/etc/php/<version>/fpm/pool.d/00-mandal-global.conf` (`process_control_timeout = 10s`, so FPM reloads finish requests in flight); `update-alternatives` for `php`, `phar`, `phpize`, `php-config` |
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
| Deploy script | `/home/<app>/deploy.sh` (`0750`, app user): Mandal's default, or the script uploaded with `--from=` |
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
| Reload | PHP-FPM for the app's version reloaded; `artisan queue:restart` run from the new release, plus `horizon:terminate` and `reverb:restart` when those packages are installed, so workers pick up the new code; any of the app's Supervisor processes that is not running (a worker added before the first deploy, for example) is started |
| Prune | Oldest releases removed down to `KEEP_RELEASES` (5 by default, 3 for the panel app) |
| Log | `/var/log/mandal/deploys/<app>/<timestamp>.log` |

A failed deploy removes `releases/<timestamp>` and never touches `current`. Rollback repoints `current`, reloads PHP-FPM and restarts the workers the same way; it does not run `deploy.sh`.

## app:configure

Rewrites the manifest's `REPO`, `BRANCH` or `KEEP_RELEASES`. For a proxied Docker app, `--port=` changes the `proxy_pass` line in `/etc/nginx/sites-available/<app>.conf` (backed up first), updates `PORT` in the manifest and reloads nginx.

## app:env

Replaces `/home/<app>/shared/.env` (Laravel) or `/srv/docker/<app>/.env` (Docker) from an uploaded file; mode and owner are kept. Nothing is restarted: redeploy the app (its `deploy.sh` runs `artisan optimize`, which caches the configuration) or restart the workers for the change to reach running processes, and recreate a Docker app's containers with **Restart**.

## app:deploy-script

Prints `/home/<app>/deploy.sh`, replaces it from an uploaded file (`--from=`, checked with `bash -n` first; `0750`, app user) or restores Mandal's default (`--reset`). `--default` prints that default without touching anything. Laravel apps only. Nothing runs until the next deploy.

## app:ssl

Runs `certbot --nginx --redirect --keep-until-expiring --expand` for the app's domain and aliases after checking they resolve to the server. Certbot edits `/etc/nginx/sites-available/<app>.conf` (certificate lines and the HTTPS redirect) and stores certificates under `/etc/letsencrypt/live/<domain>/`. The site is not rewritten by Mandal after creation (only the `proxy_pass` line of a Docker app is edited in place), so Certbot's lines stay. Renewal runs from `certbot.timer`.

## worker:add, worker:remove, worker:restart

| Action | Path |
|---|---|
| Program | `/etc/supervisor/conf.d/<app>-<worker>.conf`, running `php<v> artisan <command>` as the app user with the chosen number of processes |
| Log | `/var/log/supervisor/<app>-<worker>.log` |
| Apply | `supervisorctl reread` and `update` after add and remove (remove stops the program first); `supervisorctl restart <app>-<worker>:*` for each of the app's programs on restart |

## app:delete

Removes, in order, the nginx site, the PHP-FPM pool, every Supervisor program of the app, the database and its user (unless `--keep-database`), the crontab, the user and `/home/<app>` (unless `--keep-files`, which keeps the home and only deletes the user), the app's backup settings (`/etc/mandal/backups/app-<app>.conf` and `.exclude`, with their lines in `/etc/cron.d/mandal-backups`) and its archives under `/var/backups/mandal/apps/<app>/` (kept with `--keep-files`), the manifest. With a GitHub token, the deploy key and any webhook are removed from the repository.

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
| `app:delete` | `docker compose down --remove-orphans`, with `--volumes` unless `--keep-files`; the nginx site and `/srv/docker/<app>` removed, plus the backup settings, cron lines and (unless `--keep-files`) archives as for a Laravel app |

No system user is created for a Docker app, and no firewall rules are written: Docker publishes ports itself.

## Databases

| Command | Changes |
|---|---|
| `db:create`, `db:delete` | `CREATE DATABASE` / `DROP DATABASE` through the Unix socket as root |
| `db:user:create`, `db:user:update`, `db:user:delete` | `CREATE USER '<user>'@'localhost'`, `ALTER USER ... IDENTIFIED BY` (password read from an uploaded file), `DROP USER` |
| `db:access` | `GRANT ALL ON <db>.*` and `REVOKE` per user |

Nothing is written outside MariaDB. Databases and users that belong to an app are refused.

## Backups

Every backup target (the server's databases, or one app) has a local and a remote destination, each with its own schedule and number of copies to keep. Remotes are S3-compatible destinations the panel pushes from its Settings.

| Command | Changes |
|---|---|
| `backup:remote` | `/etc/mandal/remotes/<slug>.conf` (label, endpoint, region, bucket, prefix) and `/etc/mandal/rclone.d/<slug>.conf` (`0600`, the keys), both read from an uploaded file that is removed at once; installs `rclone` and `zstd` if missing; then `rclone lsd` on the bucket must succeed, otherwise the command fails but keeps the files so the keys can be corrected. `--remove` refuses while a target uses the remote; `--remove --force` clears `REMOTE=` on those targets (disabling one left without any destination) and prints `{"removed": true, "detached": [...], "disabled": [...]}` |
| `backup:remote-list` | Nothing; lists the remotes with `used_by` |
| `backup:configure` | `/etc/mandal/backups/databases.conf` from an uploaded file (`ENABLED`, `LOCAL`, `LOCAL_SCHEDULE`, `LOCAL_KEEP`, `REMOTE`, `REMOTE_SCHEDULE`, `REMOTE_KEEP`, `REMOTE_PATH`); `/etc/cron.d/mandal-backups`; `/var/backups/mandal/db` (`0700`). `--defaults` writes a local nightly backup (02:30, seven copies) only when the file does not exist yet; provisioning and `panel:install` run it |
| `backup:run` | `/var/backups/mandal/db/<database>/<database>-<timestamp>.sql.zst` (`0600`); the panel's SQLite copied to `/var/backups/mandal/db/<app>-sqlite/`; with a remote, `rclone copy` to `<bucket>/<prefix>/<subfolder>/db/<database>/` and the remote pruned to `REMOTE_KEEP`; local copies pruned to `LOCAL_KEEP`, or the dump removed after the upload when local is off (`--destination=local` or `remote` serve one side only). A remote that cannot be reached is logged as `failed-remote` and the dump kept locally. `/var/log/mandal/backups.log` appended. Lock `/run/lock/mandal-backup.lock` |
| `backup:restore` | A new database `<database>_restore_<time of the restore>` (or `--into=`), filled from the dump, with the original database's user granted all privileges on it. A backup that only exists remotely is downloaded first |
| `backup:delete` | One dump removed locally, or `rclone deletefile` on the remote |
| `backup:status` | Nothing; reports the database target, its last run and every app target with its last run |

### App backups

| Command | Changes |
|---|---|
| `app:backup-tree` | Nothing; lists the backup root (`/srv/docker/<app>` or `/home/<app>/shared`) two levels deep with sizes from `du` |
| `app:backup-configure` | `/etc/mandal/backups/app-<app>.conf` (the keys above plus `PAUSE=`) and `/etc/mandal/backups/app-<app>.exclude` (one `EXCLUDE=` line of the upload per line, relative to the root, no leading slash or `..`); `/etc/cron.d/mandal-backups`; `/var/backups/mandal/apps` (`0700`) |
| `app:backup` | `/var/backups/mandal/apps/<app>/<app>-<timestamp>.tar.zst` (`0600`) from `tar --zstd --anchored --wildcards --wildcards-match-slash --exclude-from=... -C <root> .`, so an exclude matches from the root only, `*` also matches `/`, and `**/name` matches at any depth; symlinks are stored as links. With `PAUSE=true` on a Docker app whose containers run, `docker compose pause` before and `unpause` after, also on failure. Refused when the filesystem has less free space than the newest archive plus 256 MB. Then the same upload (to `<bucket>/<prefix>/<subfolder>/apps/<app>/`), prune and `failed-remote` rules as `backup:run`; the run is logged as `ok app <app> <file> <size>` or `failed app <app>` |
| `app:backup-list` | Nothing; the settings, last run and archives (local and remote) newest first |
| `app:backup-delete` | One archive removed locally, or `rclone deletefile` on the remote |
| `app:backup-get` | Nothing; writes the archive bytes to stdout (`cat` or `rclone cat`) and nothing else |

## Firewall

`firewall:allow` runs `ufw allow <port>/<proto>` (with `from <cidr>` when given) and `firewall:delete` runs `ufw --force delete <number>`. ufw keeps its state in `/etc/ufw/user.rules` and `user6.rules`. The SSH rule, and the HTTP and HTTPS rules on a server hosting the panel, are refused.

## PHP versions

| Command | Changes |
|---|---|
| `php:install` | `php<v>-*` packages; `/etc/php/<v>/{cli,fpm}/conf.d/99-mandal.ini`; `/etc/php/<v>/fpm/pool.d/00-mandal-global.conf`; `php<v>-fpm` enabled |
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
| `key:add` | A labelled line appended to `/home/mandal/.ssh/authorized_keys`. When the `mandal` user or `/etc/sudoers.d/mandal` is missing (a hosted panel never runs `setup.sh`), the user is created and the rule written first |
| `key:remove` | That line removed; the last key is refused |
| `panel:install` | Everything `app:create` does for `mandal-panel`, except the MariaDB database: `shared/.env` uses SQLite at `shared/database.sqlite` (created empty, `0600`) and the database queue; `/etc/supervisor/conf.d/mandal-panel-queue.conf` runs `queue:work`; the manifest carries `PANEL=true` |
| `panel:update` | Downloads the release, then `cli:update`, `app:configure --branch=<tag>` and `app:deploy` for the panel app |

## install.sh

Runs the steps above in order on a fresh server: `setup.sh`'s CLI install (without a panel key), `provision`, `panel:install`, `app:deploy`, `app:ssl`, `php artisan db:seed` as the panel user, `php artisan mandal:register-host` and `key:add` for the panel's own key. When a panel is already installed it delegates to `panel:update` instead.
