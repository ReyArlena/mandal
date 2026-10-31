#!/usr/bin/env bash
#
# php:available / php:list / php:install / php:default / php:remove

MANDAL_COMMANDS+=("php:available|PHP versions the package source offers, as JSON|read")
MANDAL_COMMANDS+=("php:list|Installed PHP versions, the CLI default and which apps use each, as JSON|read")
MANDAL_COMMANDS+=("php:install|Install a PHP version with every extension Laravel needs (--version=8.4)|write")
MANDAL_COMMANDS+=("php:default|Make a version the CLI default (--version=8.4)|write")
MANDAL_COMMANDS+=("php:remove|Remove a PHP version no app uses (--version=8.3)|write")
MANDAL_COMMANDS+=("php:limits|Change a version's PHP-FPM limits (--version=8.5 [--upload-max-filesize=1G] [--post-max-size=1G] [--memory-limit=256M] [--max-execution-time=300] [--max-input-time=300])|write")

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
    ' | php_list_add_limits
}

# Adds each version's FPM limits to the php:list rows.
php_list_add_limits() {
    local list version limits='{}'
    list="$(cat)"
    for version in $(jq -r '.[].version' <<< "$list"); do
        limits="$(jq --arg v "$version" --argjson l "$(php_limits_json "$version")" '. + {($v): $l}' <<< "$limits")"
    done
    jq --argjson limits "$limits" 'map(. + {limits: $limits[.version]})' <<< "$list"
}

cmd_php_limits() {
    local arg version="" key value pairs=()
    for arg in "$@"; do
        case "$arg" in
            --version=*) version="${arg#*=}" ;;
            --upload-max-filesize=* | --post-max-size=* | --memory-limit=* | --max-execution-time=* | --max-input-time=*)
                key="${arg%%=*}"
                key="${key#--}"
                pairs+=("${key//-/_}=${arg#*=}")
                ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$version" ]] || die 64 "--version is required"
    validate_php_version "$version"
    [[ ${#pairs[@]} -gt 0 ]] || die 64 "give at least one limit to change"
    local -A limits=()
    for key in "${MANDAL_PHP_LIMITS[@]}"; do
        limits[$key]="$(php_limit "$version" "$key")"
    done
    for arg in "${pairs[@]}"; do
        key="${arg%%=*}"
        value="${arg#*=}"
        case "$key" in
            max_execution_time | max_input_time) validate_php_seconds "$key" "$value" ;;
            *)
                value="${value^^}"
                validate_php_size "$key" "$value"
                ;;
        esac
        limits[$key]="$value"
    done
    [[ "$(php_size_bytes "${limits[post_max_size]}")" -ge "$(php_size_bytes "${limits[upload_max_filesize]}")" ]] \
        || die 2 "post_max_size (${limits[post_max_size]}) must be at least upload_max_filesize (${limits[upload_max_filesize]}), or uploads that size fail"
    require_root
    php_version_installed "$version" || dry_run || die "PHP $version is not installed"
    if ! dry_run; then
        with_lock
        audit "php:limits $version ${pairs[*]}"
    fi
    pairs=()
    for key in "${MANDAL_PHP_LIMITS[@]}"; do
        pairs+=("$key=${limits[$key]}")
    done
    php_write_dropins "$version" "${pairs[@]}"
    if ! dry_run; then
        "php-fpm${version}" -t > /dev/null 2>&1 || die "php-fpm${version} configuration test failed"
    fi
    # The panel may run on this version and be waiting for this command.
    service_reload_no_wait "php${version}-fpm"
    printf 'PHP %s limits: %s\n' "$version" "${pairs[*]}"
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
