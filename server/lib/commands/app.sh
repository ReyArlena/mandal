#!/usr/bin/env bash
#
# app:create / app:delete - a Laravel app with its own user, PHP-FPM pool,
# nginx site, database and manifest.

MANDAL_COMMANDS+=("app:create|Create a Laravel app (user, pool, site, database)|write")
MANDAL_COMMANDS+=("app:delete|Remove an app and everything mandal created for it|write")

app_create_usage() {
    cat <<'EOF'
Usage: mandal app:create --name=<name> --domain=<domain> [options]

  --name=<name>           2-32 lower-case letters, digits or hyphens
  --domain=<domain>       Primary domain (without www)
  --alias=<domain>        Extra domain served by the app (repeatable)
  --www=<mode>            redirect-to-naked (default), redirect-to-www or naked-only
  --php=<version>         PHP version (default: the server's CLI default)
  --repo=<owner/name>     GitHub repository to deploy
  --https                 Clone over HTTPS (public repository, no deploy key needed)
  --branch=<branch>       Branch to deploy (default: main)
  --keep-releases=<n>     Releases to keep (default: 5)
  --no-database           Do not create a database and user
  --dry-run               Print what would happen without doing it
EOF
}

cmd_app_create() {
    local name="" domain="" www="redirect-to-naked" php="" repo="" branch="main" keep=5 database=true clone=ssh arg
    local aliases=()
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --domain=*) domain="${arg#*=}" ;;
            --alias=*) aliases+=("${arg#*=}") ;;
            --www=*) www="${arg#*=}" ;;
            --php=*) php="${arg#*=}" ;;
            --repo=*) repo="${arg#*=}" ;;
            --https) clone=https ;;
            --branch=*) branch="${arg#*=}" ;;
            --keep-releases=*) keep="${arg#*=}" ;;
            --no-database) database=false ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            --help | -h) app_create_usage; return 0 ;;
            *) die 64 "unknown option '$arg' (see mandal app:create --help)" ;;
        esac
    done

    [[ -n "$name" ]] || die 64 "--name is required"
    [[ -n "$domain" ]] || die 64 "--domain is required"
    validate_name "$name"
    validate_domain "$domain"
    local alias
    for alias in "${aliases[@]}"; do
        validate_domain "$alias"
    done
    validate_www_mode "$www"
    [[ -n "$repo" ]] && validate_repo "$repo"
    validate_branch "$branch"
    [[ "$keep" =~ ^[1-9][0-9]?$ ]] || die 2 "--keep-releases must be between 1 and 99"

    require_root
    load_all_drivers

    if [[ -z "$php" ]]; then
        php="$(php_default_version)"
        [[ -n "$php" ]] || die "no PHP version is installed; run mandal provision first"
    fi
    validate_php_version "$php"
    php_version_installed "$php" || dry_run || die "PHP $php is not installed (mandal php:install $php)"

    app_exists "$name" && die "app '$name' already exists"
    if ! dry_run; then
        with_lock
        audit "app:create $name"
    fi

    APP_DB_PASSWORD=""
    step "Creating app $name"
    app_create_user "$name"
    app_create_deploy_key "$name"
    app_set_php "$name" "$php"
    app_write_vhost "$name" "$php" "$www" "$domain" "${aliases[@]}"
    if [[ "$database" == true ]]; then
        app_create_database "$name"
    fi
    app_write_env "$name" "$(app_primary_hostnames "$www" "$domain")" "$APP_DB_PASSWORD"
    app_write_deploy_script "$name"
    app_write_cron "$name" "$php"
    app_write_manifest "$name" \
        "TYPE=laravel" \
        "DOMAIN=$domain" \
        "ALIASES=${aliases[*]:-}" \
        "WWW=$www" \
        "PHP=$php" \
        "SOURCE=github" \
        "REPO=$repo" \
        "CLONE=$clone" \
        "BRANCH=$branch" \
        "KEEP_RELEASES=$keep" \
        "DATABASE=$([[ "$database" == true ]] && app_db_name "$name" || echo "")" \
        "CREATED_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    printf '\nApp %s created.\n' "$name"
    if [[ -r "$(app_home "$name")/.ssh/id_ed25519.pub" ]]; then
        printf 'Deploy key: %s\n' "$(cat "$(app_home "$name")/.ssh/id_ed25519.pub")"
    fi
}

cmd_app_delete() {
    local name="" keep_database=false keep_home=false arg
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --keep-database) keep_database=true ;;
            --keep-files) keep_home=true ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    validate_name "$name"
    require_root
    load_all_drivers
    app_exists "$name" || die "app '$name' does not exist"
    if app_is_panel "$name" && [[ "${MANDAL_FORCE_PANEL:-0}" != "1" ]]; then
        die 2 "app '$name' hosts the Mandal panel; deleting it would take the panel down (set MANDAL_FORCE_PANEL=1 on the server to override)"
    fi

    local php db
    php="$(conf_get "$(app_conf_path "$name")" PHP || true)"
    db="$(conf_get "$(app_conf_path "$name")" DATABASE || true)"

    if ! dry_run; then
        with_lock
        audit "app:delete $name"
    fi

    step "Removing app $name"
    run_cmd rm -f "$MANDAL_NGINX_DIR/sites-enabled/${name}.conf" "$MANDAL_NGINX_DIR/sites-available/${name}.conf"
    nginx_check_and_reload
    if [[ -n "$php" ]]; then
        run_cmd rm -f "$MANDAL_PHP_DIR/$php/fpm/pool.d/${name}.conf"
        service_reload_or_restart "php${php}-fpm"
    fi
    for file in "$MANDAL_SUPERVISOR_DIR"/"${name}"-*.conf; do
        [[ -f "$file" ]] && run_cmd rm -f "$file"
    done
    if has_cmd supervisorctl; then
        run_cmd supervisorctl reread
        run_cmd supervisorctl update
    fi
    if [[ -n "$db" && "$keep_database" == false ]]; then
        db_sql "DROP DATABASE IF EXISTS \`${db}\`"
        db_sql "DROP USER IF EXISTS '${db}'@'localhost'"
    fi
    if id "$name" > /dev/null 2>&1 || dry_run; then
        run_cmd crontab -u "$name" -r
        if [[ "$keep_home" == true ]]; then
            run_cmd userdel "$name"
        else
            run_cmd userdel --remove "$name"
        fi
    fi
    run_cmd rm -f "$(app_conf_path "$name")"
    printf '\nApp %s removed.\n' "$name"
}
