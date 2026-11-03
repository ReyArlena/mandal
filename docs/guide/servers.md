# Servers

Mandal supports Ubuntu 24.04 and 26.04 LTS. Each server gets its own Ed25519 key pair when it is added; the private key is stored encrypted with the panel's `APP_KEY`. The first time Mandal connects it shows the server's host key fingerprint for you to confirm, then pins it. If that key ever changes, Mandal refuses to connect until you accept the new one.

## Adding a server

1. **Add the server** in the panel with its address, SSH port and the user the panel will sign in as (`mandal` by default).
2. Open the server and choose **Setup command**. Run it on the server as root:

   ```bash
   curl -fsSL https://github.com/ReyArlena/mandal/releases/latest/download/setup.sh | sudo bash -s -- --user=mandal --key='ssh-ed25519 AAAA... mandal-panel'
   ```

   `setup.sh` creates the user, installs the panel's public key, installs the `mandal` CLI from the same release under `/opt/mandal` and allows that user to run only the CLI through sudo. Beyond installing `jq`, `git` and `tar` when they are missing, nothing else is touched, and the command can be run again safely; a newer CLI already on the server is kept. The server only has to reach GitHub, never the panel. Exact changes: [files changed on the server](../reference/server-files#setup-sh).

3. Choose **Test connection**, check the fingerprint it shows against `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server, and confirm. If the CLI the release installed differs from the one this panel ships, the panel queues an update to its own build straight away.

4. Choose **Provision**. Mandal installs and hardens the stack in the background and shows the output live on the server's **Tasks** page. A queue worker must be running.

## What provisioning does

`mandal provision` installs nginx, the newest PHP with every extension Laravel needs (from the ondrej PPA, or Ubuntu's own packages while the PPA has nothing for the release), Composer, MariaDB, Dragonfly, Bun, Supervisor, Certbot, Docker, ufw, fail2ban, unattended upgrades and a swap file, sets the clock to UTC and hardens SSH. It is additive and idempotent:

- what is missing is installed; existing packages are left at their version
- Mandal's settings go in drop-in files (`sshd_config.d`, `jail.d`, `mariadb.conf.d`, nginx `conf.d` and so on) next to the distribution's, never into the main configuration files
- any file it does have to change is backed up under `/var/backups/mandal/config` first
- nginx and sshd configuration is validated before a reload
- rules, sites, databases and users it did not create are never removed
- SSH hardening (key-only logins, no root login) runs last, after the panel has already signed in with its key

Run `mandal provision --check` first to see what would be added and whether anything conflicts (Apache, for example), or `--dry-run` to print every step. Components are split into roles (`database`, `cache`, `js`) with a driver each; the choice is recorded in `/etc/mandal/server.conf`.

The full list of files is in [files changed on the server](../reference/server-files#provision).

## Moving around

The server switcher next to the logo moves between servers and keeps you on the same page. Inside a server, tabs above the page title list its pages: Server, Apps, PHP, Security, Databases, Logs, Updates, Tasks and Edit. The servers list itself has no tabs; its table shows every server. Panel settings and your profile are in the user menu.

The **Server** tab shows the connection details and the panel's public key, then what is on the server: services, PHP versions and pools, nginx sites, databases, Supervisor programs, certificates, firewall rules, Docker and the apps Mandal manages. It is read from the server on each visit and cached for 20 seconds; **Refresh** reads it again. The System card also shows whether the ntsync driver is active, with **Enable** to load it now and at boot; see [Windows game servers and ntsync](./docker-apps#windows-game-servers-and-ntsync).

Only one task runs per server at a time. A second one is refused with a message rather than queued behind the first. Every task page has **Copy output** to put the whole log on the clipboard.

**Benchmark** times what the panel spends on the server and changes nothing. It lists the panel's own setup (PHP, opcache, cached config and routes, debug, drivers, the `sodium` and `gmp` extensions phpseclib's SSH crypto uses), times the SSH connection, a no-op round trip and the cost of `sudo`, reads the inventory fresh and from the cache, then runs `mandal benchmark` on the server: the cost of starting a process, each inventory collector on its own and every read command a page runs, slowest first. It takes about a minute and ends with a JSON block to share when reporting a slow panel.

## Keeping the CLI current

Every connection records the server's CLI build. When it differs from the panel's, the servers list shows an **Update required** badge in its CLI column, every user gets a persistent notification in the bell menu and the server page enables **Update CLI** (greyed out with the current build otherwise), which uploads the panel's copy over SFTP and installs it with `mandal cli:update`. `php artisan mandal:check-updates` runs hourly and checks every connected server, so the scheduler must be running.

## Several panels, one server

Everything Mandal knows about a server lives on the server, so any panel with an accepted key can manage it. To let a second panel in, add that panel's public key (shown on its server page) with **Add key** under the first panel's **Security > SSH** page, or on the server:

```bash
sudo mandal key:add --name="Laptop panel" --key="ssh-ed25519 ..."
```

Keys are labelled and listed on the SSH page (`key:list`), where they can be removed again (`key:remove --fingerprint=`). The last key can never be removed, and a panel never removes its own. The same mechanism is how you get a shell on a hardened server: grant your own public key, then SSH in as the `mandal` user.
