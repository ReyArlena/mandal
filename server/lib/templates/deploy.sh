#!/usr/bin/env bash
#
# Runs inside the new release directory as the app user, after the code
# has been cloned and shared files linked, and before the release goes
# live. Edit freely; it is yours. Available: $MANDAL_APP, $RELEASE_DIR,
# $SHARED_DIR, $PHP (the app's PHP binary), $BRANCH, $COMMIT.

set -euo pipefail

$PHP /usr/local/bin/composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [ -f package.json ]; then
    bun install --frozen-lockfile
    bun run build
fi

$PHP artisan migrate --force
$PHP artisan storage:link
$PHP artisan optimize
