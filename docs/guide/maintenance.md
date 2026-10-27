# PHP, firewall, updates and logs

## PHP versions

The **PHP** page lists installed versions, which apps use each one and the CLI default, and installs any version the package source offers with every extension Laravel needs (`mandal php:install`). Mandal adds a drop-in `99-mandal.ini` to each SAPI of a version it installs; the distribution's `php.ini` is not edited. A version cannot be removed while an app uses it. The newest version with all required extensions is installed during provisioning and made the default for the `php` command.

## Firewall

The **Firewall** page shows the ufw rules, allows a port (optionally from one address only, TCP or UDP, with a comment) and deletes rules by number. The SSH rule can never be deleted, and the HTTP and HTTPS rules are protected while the server hosts the panel.

Provisioning sets the defaults to deny incoming and allow outgoing, then allows the SSH port, 80 and 443. Rules that were there before are kept.

The Docker section lists ports that containers publish past ufw. See [Docker apps](./docker-apps#ports-and-the-firewall).

## Updates

Ubuntu security updates, PHP patch releases and Docker engine updates install themselves through unattended-upgrades, with no automatic reboot. The **Updates** page shows everything else:

- pending packages split into security and other, and held packages
- whether a reboot is needed and why, and the running kernel against the newest installed one
- the last automatic update run
- each component's installed and latest version: Dragonfly, Bun and Composer are checked against their releases (cached on the server for six hours), Docker against the apt candidate
- recent package changes from the apt history

From the page you can install security updates, all updates or a single package, update a component (`mandal component:update`), update the CLI and reboot. Existing configuration files are always kept (`apt` runs with the options that keep the local version). Rebooting a server that hosts the panel takes the panel offline until it is back; the page warns before doing it.

## Logs

The **Logs** page reads logs straight from the server. Sources are addressed by name, never by path, so the panel can only read what the CLI lists:

- service journals: nginx, PHP-FPM, MariaDB, Dragonfly, Supervisor, Docker, fail2ban, SSH
- the kernel and authentication journals
- global nginx and PHP-FPM logs, fail2ban, package updates, Mandal's own action log
- per app: nginx access and error logs, the Laravel log (one entry per message with stack traces attached, filterable by level), the PHP slow log, worker logs and the latest deployment; for Docker apps, the nginx logs and the container output

Filter by text, limit the lines and switch on **Tail** to follow a log live. Line-based logs keep scrolling with new lines; scroll up to read, and use **Jump to present** to return. The Laravel log is shown as a list of entries instead. Task output on the Tasks page follows the same way.
