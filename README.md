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

## Development

```bash
composer run dev
```

This starts the development server, queue worker, log viewer and Vite together.

### Tests

```bash
bunx playwright install chromium   # once, for the browser tests
composer run test
```
