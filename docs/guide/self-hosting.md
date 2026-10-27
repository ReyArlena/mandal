# Hosting the panel on a managed server

The usual way to run Mandal is on a server it manages. The panel is then an ordinary Laravel app on that server, flagged so the panel cannot harm itself.

## Installing

Point a domain at a fresh Ubuntu 26.04 server and run as root:

```bash
curl -fsSL https://github.com/ReyArlena/mandal/releases/latest/download/install.sh | sudo bash -s -- --domain=panel.example.com
```

| Option | Meaning |
|---|---|
| `--domain=` | The panel's domain. Required the first time; later runs read it from the installed panel. |
| `--email=` | Contact address for the Let's Encrypt certificate. |
| `--version=` | Install a specific release tag instead of the latest. |
| `--skip-ssl` | Leave the certificate for later. |
| `--repo=` | Install from another GitHub repository (a fork). |
| `--name=` | The app name for the panel (`mandal-panel` by default). |
| `--source=` | Install the CLI and the panel from a local checkout instead of GitHub, for testing. |

The script works through these steps, printing what it does:

1. Installs the `mandal` CLI from the release under `/opt/mandal`.
2. Provisions the server with the default drivers (`mandal provision`). To choose other drivers, run `mandal provision` by hand first; the script skips provisioning when it has been done.
3. Creates the panel app with `mandal panel:install`: a `mandal-panel` user, PHP-FPM pool and nginx site, a production `.env` with SQLite under `shared/`, the database queue and a Supervisor queue worker.
4. Deploys the panel from the release tag over public HTTPS (`mandal app:deploy`).
5. Requests a certificate (`mandal app:ssl`), unless skipped.
6. Creates the first user (`php artisan db:seed`) and prints the sign-in details.
7. Registers the server in its own panel (`php artisan mandal:register-host`) and allows the panel's key with `mandal key:add`, so the panel manages the server it runs on.

Every step is skipped when it is already done, so the script resumes after an interruption. The pieces can also be run by hand in the same order.

## What is protected

The panel app is flagged in its manifest (`PANEL=true`). While it exists:

- it cannot be deleted from the panel
- the HTTP and HTTPS firewall rules cannot be deleted
- rebooting from the panel warns that the panel goes offline
- the panel's SQLite database lives under `shared/` so deploys keep it, and the database backup (nightly by default) copies it along with the MariaDB dumps

Keep a copy of `shared/database.sqlite` together with the `APP_KEY` from `shared/.env` somewhere else. Encrypted values (server private keys, the GitHub token) cannot be read without the key.

## Updating

The panel checks GitHub for a newer release every hour. When one exists, every user gets a persistent notification and the hosting server's page shows the update command. The update runs on the server itself, because deploying the panel restarts the queue worker that would otherwise drive it:

```bash
ssh mandal@panel.example.com
sudo mandal panel:update
```

`panel:update` resolves the latest release (or `--version=`), installs that release's CLI, points the panel app at the release tag and deploys it. The panel is back a few seconds after the deploy switches over. It can be run again if interrupted; every step is skipped when already done. Running `install.sh` again does the same.

Root logins are disabled once the server is provisioned, so to SSH in you need your own public key allowed with **Grant access** on the server's page (or `mandal key:add` while you still have a root session). You then sign in as the `mandal` user, which can run only `sudo mandal`.

## Managing from elsewhere too

A hosted panel and a panel on your laptop can manage the same servers, including the hosting server. Add the second panel's key with **Grant access**; see [several panels, one server](./servers#several-panels-one-server).
