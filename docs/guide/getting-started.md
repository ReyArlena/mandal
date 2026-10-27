# Getting started

Mandal is a Laravel 13 and Filament 5 panel that manages servers over SSH. On each server it installs a Bash CLI, `mandal`, which is the only way the panel reads or changes anything there. Nothing about a server is stored in the panel beyond its address and the key pair used to reach it.

There are two ways to run the panel.

## Hosted on a managed server (recommended)

Point a domain at a fresh Ubuntu 26.04 server and run, as root:

```bash
curl -fsSL https://github.com/ReyArlena/mandal/releases/latest/download/install.sh | sudo bash -s -- --domain=panel.example.com
```

`install.sh` installs the CLI from the latest release, provisions the server, creates the panel as a protected app, deploys it from the release tag, requests a Let's Encrypt certificate, creates the first user and registers the server in the panel so it manages itself. [Hosting the panel](./self-hosting) covers the options, updates and what the script does on a second run.

## On your own machine

Useful for development, or when you only need to manage servers from a laptop. Servers never need to reach the panel, only GitHub, so a panel behind NAT works. Requirements:

- PHP 8.3 or newer, with the `sqlite3`, `mbstring`, `intl`, `curl` and `zip` extensions
- Composer
- Bun

```bash
git clone https://github.com/ReyArlena/mandal.git
cd mandal
composer run setup
php artisan db:seed
composer run dev
```

`composer run setup` installs dependencies, creates `.env`, generates the application key, runs migrations and builds the front-end assets. The seed step creates the initial administrator. `composer run dev` starts the development server, the queue worker, the log viewer and Vite together; the queue worker is needed because provisioning, deployments and every other long task run as a job.

## First sign-in

| Field | Value |
|---|---|
| Email | `admin@admin.com` |
| Password | `changethispassword` |

On first sign-in you set up an authenticator app. Multi-factor authentication is required for every account. Once in, open your profile from the user menu and change the email address and password. There is no public registration page; additional users are created inside the panel.

## Running in production

Mandal holds credentials for every server it manages, so treat it as the most sensitive application you run. `install.sh` sets all of this up on a hosted panel; for any other deployment:

- Serve it over HTTPS only.
- In `.env`, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the public address and `SESSION_SECURE_COOKIE=true`.
- Run `php artisan optimize` after each deployment.
- Run a queue worker (`php artisan queue:work`) under a process manager such as Supervisor.
- Add `* * * * * php artisan schedule:run` to the crontab of the user that owns the application. The hourly release and CLI checks depend on it.
- Back up `database/database.sqlite` together with the `APP_KEY` from `.env`. Encrypted values in the database cannot be read without the key.

## Next steps

1. [Add a server](./servers) and run its setup command.
2. Provision it.
3. Create a [Laravel app](./apps) or a [Docker app](./docker-apps).
