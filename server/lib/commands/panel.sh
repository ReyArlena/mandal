#!/usr/bin/env bash
#
# panel:install - set this server up to host the Mandal panel itself as a
# normal app, flagged so that nothing can pull the rug from under it.

MANDAL_COMMANDS+=("panel:install|Create an app for hosting the panel on this server (--name= --domain= --repo=)|write")

# The panel's own .env: SQLite in shared/ so it survives deploys, database
# queue and cache so no extra service is involved.
panel_render_env() {
    local name="$1" domain="$2" home
    home="$(app_home "$name")"
    cat <<EOF
APP_NAME="Mandal"
APP_ENV=production
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_DEBUG=false
APP_URL=https://${domain}

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=${home}/shared/database.sqlite

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
EOF
}

cmd_panel_install() {
    local name="mandal-panel" domain="" repo="" branch="main" php="" clone=ssh arg
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --domain=*) domain="${arg#*=}" ;;
            --repo=*) repo="${arg#*=}" ;;
            --branch=*) branch="${arg#*=}" ;;
            --php=*) php="${arg#*=}" ;;
            --https) clone=https ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$domain" ]] || die 64 "--domain is required"
    [[ -n "$repo" ]] || die 64 "--repo is required (the repository the panel is deployed from)"
    validate_name "$name"
    validate_domain "$domain"
    validate_repo "$repo"
    validate_branch "$branch"
    require_root
    load_all_drivers
    local existing
    existing="$(panel_apps | awk 'NR == 1')"
    if [[ "$existing" == "$name" ]]; then
        # install.sh runs this on every run, so an installed panel is not an error.
        printf 'Panel app %s already exists; nothing to do.\n' "$name"
        return 0
    fi
    [[ -z "$existing" ]] || die "this server already hosts the panel as app '$existing'"
    app_exists "$name" && die "app '$name' already exists"
    if [[ -z "$php" ]]; then
        php="$(php_default_version)"
        [[ -n "$php" ]] || die "no PHP version is installed; run mandal provision first"
    fi
    validate_php_version "$php"

    if ! dry_run; then
        with_lock
        audit "panel:install $name"
    fi

    step "Creating the panel app $name"
    app_create_user "$name"
    app_create_deploy_key "$name"
    app_set_php "$name" "$php"
    app_write_vhost "$name" "$php" naked-only "$domain"
    write_file "$(app_home "$name")/shared/.env" 0600 "$name:$name" < <(panel_render_env "$name" "$domain")
    run_cmd install -m 0600 -o "$name" -g "$name" /dev/null "$(app_home "$name")/shared/database.sqlite"
    app_write_deploy_script "$name"
    app_write_cron "$name" "$php"
    app_write_manifest "$name" \
        "TYPE=laravel" \
        "PANEL=true" \
        "DOMAIN=$domain" \
        "ALIASES=" \
        "WWW=naked-only" \
        "PHP=$php" \
        "SOURCE=github" \
        "REPO=$repo" \
        "CLONE=$clone" \
        "BRANCH=$branch" \
        "KEEP_RELEASES=3" \
        "DATABASE=" \
        "CREATED_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    step "Adding the panel's queue worker"
    run_cmd install -d -m 0755 /var/log/supervisor
    write_file "$(worker_conf_path "$name" queue)" 0644 < <(worker_render "$name" queue "$php" "queue:work --sleep=3 --tries=1 --max-time=3600" 1)
    worker_apply

    printf '\nPanel app %s created. Next:\n' "$name"
    printf '  1. Add the deploy key below to %s (or use --https for a public repository).\n' "$repo"
    printf '  2. mandal app:deploy --name=%s\n' "$name"
    printf '  3. mandal app:ssl --name=%s\n' "$name"
    printf '  4. sudo -u %s -H /usr/bin/php%s %s/current/artisan db:seed   (creates the first user)\n' "$name" "$php" "$(app_home "$name")"
    if [[ -r "$(app_home "$name")/.ssh/id_ed25519.pub" ]]; then
        printf 'Deploy key: %s\n' "$(cat "$(app_home "$name")/.ssh/id_ed25519.pub")"
    fi
}
