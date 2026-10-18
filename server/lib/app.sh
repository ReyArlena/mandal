#!/usr/bin/env bash
#
# Apps: one Linux user, PHP-FPM pool, nginx site, database and manifest
# per app, in the standard locations. Templates are rendered by functions
# that print to stdout so they can be checked without a server.

MANDAL_APP_WWW_MODES=(redirect-to-naked redirect-to-www naked-only)
MANDAL_NGINX_USER="${MANDAL_NGINX_USER:-www-data}"
MANDAL_CRON_DIR="${MANDAL_CRON_DIR:-/var/spool/cron/crontabs}"

app_home() {
    printf '%s/%s\n' "$MANDAL_HOME_DIR" "$1"
}

app_socket() {
    printf '/run/php/php%s-fpm-%s.sock\n' "$2" "$1"
}

app_db_name() {
    printf '%s\n' "${1//-/_}"
}

# Names of apps flagged as hosting the Mandal panel, one per line.
panel_apps() {
    local file
    for file in "$MANDAL_APPS_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        [[ "$(conf_get "$file" PANEL 2> /dev/null || true)" == "true" ]] && basename "$file" .conf
    done
}

app_is_panel() {
    [[ "$(conf_get "$(app_conf_path "$1")" PANEL 2> /dev/null || true)" == "true" ]]
}

server_hosts_panel() {
    [[ -n "$(panel_apps)" ]]
}

validate_www_mode() {
    local mode
    for mode in "${MANDAL_APP_WWW_MODES[@]}"; do
        [[ "$1" == "$mode" ]] && return 0
    done
    die 2 "invalid www mode '$1' (expected one of: ${MANDAL_APP_WWW_MODES[*]})"
}

validate_branch() {
    [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$ && "$1" != *..* && "$1" != */ && "$1" != *.lock ]] || die 2 "invalid branch '$1'"
}

validate_repo() {
    [[ "$1" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]] || die 2 "invalid repository '$1' (expected owner/name)"
}

# ---------------------------------------------------------------------------
# Host names an app answers to
# ---------------------------------------------------------------------------

# All names served or redirected: the domain and aliases, each with or
# without www according to the mode.
app_hostnames() {
    local mode="$1" domain name
    shift
    for domain in "$@"; do
        name="${domain#www.}"
        printf '%s\n' "$name"
        [[ "$mode" == "naked-only" ]] || printf 'www.%s\n' "$name"
    done
}

# Names that serve the app itself (not redirected).
app_primary_hostnames() {
    local mode="$1" domain name
    shift
    for domain in "$@"; do
        name="${domain#www.}"
        if [[ "$mode" == "redirect-to-www" ]]; then
            printf 'www.%s\n' "$name"
        else
            printf '%s\n' "$name"
        fi
    done
}

# Names that redirect to the primary name.
app_redirect_hostnames() {
    local mode="$1" domain name
    shift
    [[ "$mode" == "naked-only" ]] && return 0
    for domain in "$@"; do
        name="${domain#www.}"
        if [[ "$mode" == "redirect-to-www" ]]; then
            printf '%s\n' "$name"
        else
            printf 'www.%s\n' "$name"
        fi
    done
}

# ---------------------------------------------------------------------------
# Templates
# ---------------------------------------------------------------------------

# app_render_vhost name php mode domain [alias...]
app_render_vhost() {
    local name="$1" php="$2" mode="$3" home socket primary redirects target
    shift 3
    home="$(app_home "$name")"
    socket="$(app_socket "$name" "$php")"
    primary="$(app_primary_hostnames "$mode" "$@" | tr '\n' ' ')"
    primary="${primary% }"
    redirects="$(app_redirect_hostnames "$mode" "$@" | tr '\n' ' ')"
    redirects="${redirects% }"
    target="$(app_primary_hostnames "$mode" "$1")"

    printf '# managed-by: mandal app=%s\n' "$name"
    if [[ -n "$redirects" ]]; then
        cat <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${redirects};
    server_tokens off;
    return 301 \$scheme://${target}\$request_uri;
}

EOF
    fi
    cat <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${primary};
    server_tokens off;
    root ${home}/current/public;

    index index.php;
    charset utf-8;

    access_log /var/log/nginx/${name}-access.log;
    error_log /var/log/nginx/${name}-error.log;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\\.php(/|\$) {
        fastcgi_pass unix:${socket};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ \\.php\$ {
        return 404;
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF
}

# app_render_pool name php
app_render_pool() {
    local name="$1" php="$2" home
    home="$(app_home "$name")"
    cat <<EOF
; managed-by: mandal app=${name}
[${name}]
user = ${name}
group = ${name}

listen = $(app_socket "$name" "$php")
listen.owner = ${MANDAL_NGINX_USER}
listen.group = ${MANDAL_NGINX_USER}
listen.mode = 0660

pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 10s
pm.max_requests = 500

request_slowlog_timeout = 5s
slowlog = /var/log/php${php}-fpm-${name}-slow.log

php_admin_value[open_basedir] = ${home}:/tmp:/dev/urandom
php_admin_value[upload_tmp_dir] = ${home}/tmp
php_admin_value[sys_temp_dir] = ${home}/tmp
php_admin_flag[expose_php] = off

env[PATH] = ${home}/.local/bin:/usr/local/bin:/usr/bin:/bin
env[HOME] = ${home}
EOF
}

# app_render_env name domain db_name db_user db_password cache_password
app_render_env() {
    local name="$1" domain="$2" db_name="$3" db_user="$4" db_password="$5" cache_password="$6"
    cat <<EOF
APP_NAME="${name}"
APP_ENV=production
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_DEBUG=false
APP_URL=https://${domain}

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${db_name}
DB_USERNAME=${db_user}
DB_PASSWORD="${db_password}"

SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD="${cache_password}"
REDIS_PORT=6379

MAIL_MAILER=log
EOF
}

# app_render_deploy_script
app_render_deploy_script() {
    cat <<'EOF'
#!/usr/bin/env bash
#
# Runs inside the new release directory as the app user, after the code
# has been cloned and shared files linked, and before the release goes
# live. Edit freely; it is yours. Available: $APP_NAME, $RELEASE_DIR,
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
EOF
}

app_render_cron() {
    local name="$1" php="$2"
    printf '# managed-by: mandal app=%s\n* * * * * /usr/bin/php%s %s/current/artisan schedule:run >> /dev/null 2>&1\n' "$name" "$php" "$(app_home "$name")"
}

# ---------------------------------------------------------------------------
# Creation steps
# ---------------------------------------------------------------------------

app_create_user() {
    local name="$1" home
    home="$(app_home "$name")"
    if id "$name" > /dev/null 2>&1; then
        printf '+ user %s already exists\n' "$name"
    else
        run_cmd useradd --create-home --home-dir "$home" --shell /bin/bash --user-group "$name"
    fi
    run_cmd chmod 0750 "$home"
    run_cmd install -d -m 0755 -o "$name" -g "$name" "$home/releases" "$home/shared" "$home/shared/storage" "$home/tmp" "$home/.local" "$home/.local/bin"
    run_cmd install -d -m 0700 -o "$name" -g "$name" "$home/.ssh"
    for dir in app app/public framework framework/cache framework/sessions framework/views logs; do
        run_cmd install -d -m 0755 -o "$name" -g "$name" "$home/shared/storage/$dir"
    done
    # nginx may traverse the home and read releases and storage, nothing else.
    run_cmd setfacl -m "u:${MANDAL_NGINX_USER}:x" "$home"
    run_cmd setfacl -R -m "u:${MANDAL_NGINX_USER}:rX" -m "d:u:${MANDAL_NGINX_USER}:rX" "$home/releases" "$home/shared/storage"
}

app_create_deploy_key() {
    local name="$1" home
    home="$(app_home "$name")"
    if [[ -f "$home/.ssh/id_ed25519" ]]; then
        printf '+ deploy key already exists\n'
        return 0
    fi
    run_cmd sudo -u "$name" ssh-keygen -q -t ed25519 -N "" -C "${name}@$(hostname)" -f "$home/.ssh/id_ed25519"
    if ! dry_run; then
        ssh-keyscan -t ed25519,rsa github.com 2> /dev/null >> "$home/.ssh/known_hosts" || warn "could not fetch GitHub's host keys; the first clone will prompt"
        chown "$name:$name" "$home/.ssh/known_hosts"
        chmod 0600 "$home/.ssh/known_hosts"
    fi
}

app_set_php() {
    local name="$1" php="$2" home
    home="$(app_home "$name")"
    run_cmd ln -sfn "/usr/bin/php${php}" "$home/.local/bin/php"
    write_file "$MANDAL_PHP_DIR/$php/fpm/pool.d/${name}.conf" 0644 < <(app_render_pool "$name" "$php")
    if ! dry_run; then
        "php-fpm${php}" -t > /dev/null 2>&1 || die "php-fpm${php} configuration test failed"
    fi
    service_reload_or_restart "php${php}-fpm"
}

app_write_vhost() {
    local name="$1"
    write_file "$MANDAL_NGINX_DIR/sites-available/${name}.conf" 0644 < <(app_render_vhost "$@")
    run_cmd ln -sfn "$MANDAL_NGINX_DIR/sites-available/${name}.conf" "$MANDAL_NGINX_DIR/sites-enabled/${name}.conf"
    nginx_check_and_reload
}

# Sets APP_DB_PASSWORD for the caller.
# shellcheck disable=SC2034
app_create_database() {
    local name="$1" db user password
    db="$(app_db_name "$name")"
    user="$db"
    password="$(random_secret 32)"
    APP_DB_PASSWORD="$password"
    db_sql "CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    db_sql "CREATE USER IF NOT EXISTS '${user}'@'localhost' IDENTIFIED BY '${password}'"
    db_sql "ALTER USER '${user}'@'localhost' IDENTIFIED BY '${password}'"
    db_sql "GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${user}'@'localhost'"
    db_sql "FLUSH PRIVILEGES"
}

app_write_env() {
    local name="$1" domain="$2" db_password="$3" home db
    home="$(app_home "$name")"
    db="$(app_db_name "$name")"
    if [[ -f "$home/shared/.env" ]]; then
        printf '+ %s/shared/.env already exists, keeping it\n' "$home"
        return 0
    fi
    cache_password
    # shellcheck disable=SC2153  # set by cache_password
    write_file "$home/shared/.env" 0600 "$name:$name" < <(app_render_env "$name" "$domain" "$db" "$db" "$db_password" "$CACHE_PASSWORD")
}

app_write_deploy_script() {
    local name="$1" home
    home="$(app_home "$name")"
    if [[ -f "$home/deploy.sh" ]]; then
        printf '+ deploy.sh already exists, keeping it\n'
        return 0
    fi
    write_file "$home/deploy.sh" 0750 "$name:$name" < <(app_render_deploy_script)
}

app_write_cron() {
    local name="$1" php="$2" tmp
    printf '+ crontab for %s\n' "$name"
    dry_run && return 0
    tmp="$(mktemp)"
    app_render_cron "$name" "$php" > "$tmp"
    crontab -u "$name" "$tmp"
    rm -f "$tmp"
}

app_write_manifest() {
    local name="$1" file
    shift
    file="$(app_conf_path "$name")"
    printf '+ write %s\n' "$file"
    dry_run && return 0
    mkdir -p "$MANDAL_APPS_DIR"
    : > "$file"
    chmod 0644 "$file"
    local pair
    for pair in "$@"; do
        conf_set "$file" "${pair%%=*}" "${pair#*=}"
    done
}
