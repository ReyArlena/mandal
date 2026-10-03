#!/usr/bin/env bash
#
# php:available / php:list / php:install / php:default / php:remove

MANDAL_COMMANDS+=("php:available|PHP versions the package source offers, as JSON|read")
MANDAL_COMMANDS+=("php:list|Installed PHP versions, the CLI default and which apps use each, as JSON|read")
MANDAL_COMMANDS+=("php:install|Install a PHP version with every extension Laravel needs (--version=8.4)|write")
MANDAL_COMMANDS+=("php:default|Make a version the CLI default (--version=8.4)|write")
MANDAL_COMMANDS+=("php:remove|Remove a PHP version no app uses (--version=8.3)|write")

php_parse_version() {
    local arg
    PHP_VERSION=""
    for arg in "$@"; do
        case "$arg" in
            --version=*) PHP_VERSION="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$PHP_VERSION" ]] || die 64 "--version is required"
    validate_php_version "$PHP_VERSION"
}

# Apps using a version, one name per line.
php_apps_using() {
    local file
    for file in "$MANDAL_APPS_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        [[ "$(conf_get "$file" PHP 2> /dev/null || true)" == "$1" ]] && basename "$file" .conf
    done
}

cmd_php_available() {
    require_root
    local v
    for v in $(php_available_versions); do
        printf '%s\t%s\t%s\n' "$v" "$(php_version_complete "$v" && echo true || echo false)" "$(php_version_installed "$v" && echo true || echo false)"
    done | json_tsv version complete installed | jq 'map(.complete |= (. == "true") | .installed |= (. == "true"))'
}

cmd_php_list() {
    require_root
    local v
    for v in $(php_installed_versions); do
        printf '%s\t%s\t%s\n' "$v" "$(php_apps_using "$v" | tr '\n' ' ' | sed 's/ $//')" "$(package_version "php${v}-cli")"
    done | json_tsv version apps package | jq --arg default "$(php_default_version)" '
        map(.apps |= (split(" ") | map(select(length > 0))) | . + {default: (.version == $default)})
    '
}

cmd_php_install() {
    php_parse_version "$@"
    require_root
    load_all_drivers
    if ! dry_run; then
        with_lock
        audit "php:install $PHP_VERSION"
    fi
    step "Installing PHP $PHP_VERSION"
    php_add_ppa
    php_version_complete "$PHP_VERSION" || dry_run || die "PHP $PHP_VERSION is not available with every required extension yet"
    php_install_version "$PHP_VERSION"
    printf '\nPHP %s installed.\n' "$PHP_VERSION"
}

cmd_php_default() {
    php_parse_version "$@"
    require_root
    php_version_installed "$PHP_VERSION" || dry_run || die "PHP $PHP_VERSION is not installed"
    if ! dry_run; then
        with_lock
        audit "php:default $PHP_VERSION"
    fi
    php_set_default "$PHP_VERSION"
    printf 'PHP %s is now the CLI default.\n' "$PHP_VERSION"
}

cmd_php_remove() {
    php_parse_version "$@"
    require_root
    local using
    using="$(php_apps_using "$PHP_VERSION" | tr '\n' ' ' | sed 's/ $//')"
    [[ -z "$using" ]] || die 2 "PHP $PHP_VERSION is used by: $using"
    [[ "$(php_default_version)" != "$PHP_VERSION" ]] || die 2 "PHP $PHP_VERSION is the CLI default; choose another default first"
    php_version_installed "$PHP_VERSION" || dry_run || die "PHP $PHP_VERSION is not installed"
    if ! dry_run; then
        with_lock
        audit "php:remove $PHP_VERSION"
    fi
    step "Removing PHP $PHP_VERSION"
    run_cmd apt-get remove --purge "${APT_OPTIONS[@]}" "php${PHP_VERSION}-*"
    run_cmd apt-get autoremove "${APT_OPTIONS[@]}"
    printf '\nPHP %s removed.\n' "$PHP_VERSION"
}
