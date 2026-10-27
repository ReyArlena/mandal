# Laravel apps

The **Apps** page creates Laravel apps on a provisioned server. Each app gets:

- its own system user and home, `/home/<app>`, with `releases/`, `shared/` and `current` (the live release)
- a PHP-FPM pool running as that user, listening on a Unix socket
- an nginx site with per-app access and error logs, serving the chosen domain, aliases and `www` redirect mode
- a MariaDB database and user, with the password written into the app's `.env`
- a production `.env` under `shared/.env`, linked into every release
- the scheduler crontab (`artisan schedule:run` every minute)
- an Ed25519 deploy key and an editable `deploy.sh`

The exact paths are listed under [app:create](../reference/server-files#app-create).

## Deploying

Add the app's deploy key (shown on its page) to the GitHub repository; read-only access is enough. Public repositories can be cloned over HTTPS instead, without a key.

**Deploy** clones the configured branch into `releases/<timestamp>`, links `shared/storage` and `shared/.env` into it, runs `deploy.sh` as the app user (Composer, Bun build, migrations, `optimize`), then switches the `current` symlink, reloads PHP-FPM and restarts the app's workers. The switch only happens when `deploy.sh` succeeds; a failed deploy removes its release and leaves the live one alone. Old releases are pruned down to the number configured for the app (five by default).

**Roll back** switches `current` to the previous release, or to any release listed on the app's page, without running `deploy.sh`.

`deploy.sh` lives at `/home/<app>/deploy.sh`, belongs to the app user and can be edited over SSH. It runs inside the new release directory with `$MANDAL_APP`, `$RELEASE_DIR`, `$SHARED_DIR`, `$PHP`, `$BRANCH` and `$COMMIT` set. The default runs `composer install --no-dev`, `bun install --frozen-lockfile` and `bun run build` when a `package.json` exists, then `artisan migrate --force`, `artisan storage:link` and `artisan optimize`. Each release records its branch, commit and time in `.mandal-release`, which is what the releases list shows.

## GitHub and automatic deploys

Under **Settings**, connect a fine-grained GitHub personal access token with repository permissions *Administration (read and write)*, *Webhooks (read and write)* and *Contents (read)* for the repositories you deploy. Mandal checks the token, stores it encrypted and shows when it expires. From then on, creating an app adds its deploy key to the repository and deleting the app removes it.

To deploy on every push, set the panel's **Public URL** in Settings (reachable from the internet over HTTPS), then use **Enable auto deploy** on the app. Mandal adds a webhook to the repository and asks GitHub for a test delivery; if that delivery does not reach the panel, the webhook is removed again and the reason is shown. Only pushes to the app's configured branch deploy. Deliveries are verified with `X-Hub-Signature-256`, handled once, and refused while another task runs on the server. Manual deploys always work, so a panel on your own machine simply does without auto deploy.

## The app page

- **Details**: domain and www mode, PHP version, repository and branch, auto deploy, database, user and home, and the deploy key to add to the repository. Repository, branch and releases to keep are changed on the server with `mandal app:configure --repo= --branch= --keep-releases=`.
- **Releases**: every release with its commit, and a **Roll back** button per release.
- **.env**: edit the production environment file. It is uploaded as a file, never passed on a command line.
- **SSL**: requests a Let's Encrypt certificate with Certbot's nginx plugin after checking that the domain and every alias resolve to the server. Certbot edits the nginx site to add the certificate and the HTTPS redirect, and Mandal does not rewrite the site afterwards, so those lines stay.
- **Workers**: Supervisor programs running an artisan command (`queue:work`, `horizon`, `reverb:start`, anything else) as the app user, with the number of processes. Buttons in the add form fill in a queue worker, Horizon, Reverb or `schedule:work` (the last only for apps whose crontab entry was removed, since Mandal already runs `schedule:run` every minute). Every worker restarts after each deploy; **Restart workers** restarts them all, and each row has its own **Restart** (Supervisor sends SIGTERM, which `queue:work` and Horizon treat as "finish the current job, then exit").
- **Logs**: opens the Logs page on the app's Laravel log.
- **Delete** removes everything the app created. Options keep the database or the home directory.

## PHP version

Each app runs on the PHP version chosen at creation. The **PHP** page installs further versions and shows which apps use each one; a version cannot be removed while an app uses it.
