#!/usr/bin/env bash
set -euo pipefail

$PHP /usr/local/bin/composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [ -f package.json ]; then
    bun install --frozen-lockfile
    bun run build
fi

$PHP artisan migrate --force
$PHP artisan storage:link
$PHP artisan optimize
