#!/usr/bin/env bash
#
# PHP versions: discovery through apt, installation and drop-in config.

MANDAL_PHP_PPA="ppa:ondrej/php"
MANDAL_PHP_PPA_URL="${MANDAL_PHP_PPA_URL:-https://ppa.launchpadcontent.net/ondrej/php/ubuntu}"

# Extension packages every version must provide (php<ver>-<name>).
MANDAL_PHP_EXTENSIONS=(fpm cli bcmath curl intl mbstring mysql xml zip gd redis sqlite3 readline)

# Extensions that exist only for some versions (built in elsewhere).
MANDAL_PHP_OPTIONAL_EXTENSIONS=(opcache)

php_ppa_present() {
    grep -rqs "ondrej/php" "$MANDAL_APT_SOURCES_DIR"/ 2> /dev/null
}

# The PPA publishes packages for a new Ubuntu release some time after the
# release itself. Until then, the PHP that Ubuntu ships is used instead.
php_ppa_supports_release() {
    curl -fsSL -o /dev/null --retry 2 "${MANDAL_PHP_PPA_URL}/dists/$(os_codename)/Release" 2> /dev/null
}

php_add_ppa() {
    if php_ppa_present; then
        printf '+ PHP PPA already present\n'
        return 0
    fi
    if ! php_ppa_supports_release; then
        printf '+ the PHP PPA has no packages for %s yet; using the PHP packages Ubuntu ships\n' "$(os_codename)"
        return 0
    fi
    apt_install software-properties-common
    run_cmd add-apt-repository -y "$MANDAL_PHP_PPA"
    apt_update --force
}

# Versions apt knows a php<ver>-fpm package for, oldest first.
php_available_versions() {
    apt-cache search --names-only '^php[0-9]+\.[0-9]+-fpm$' 2> /dev/null | grep -oP '^php\K[0-9]+\.[0-9]+' | sort -V | uniq
}

# Package names required for a version.
php_packages_for() {
    local version="$1" extension
    for extension in "${MANDAL_PHP_EXTENSIONS[@]}"; do
        printf 'php%s-%s\n' "$version" "$extension"
    done
    for extension in "${MANDAL_PHP_OPTIONAL_EXTENSIONS[@]}"; do
        if apt_package_exists "php${version}-${extension}"; then
            printf 'php%s-%s\n' "$version" "$extension"
        fi
    done
}

php_version_complete() {
    local version="$1" extension
    for extension in "${MANDAL_PHP_EXTENSIONS[@]}"; do
        apt_package_exists "php${version}-${extension}" || return 1
    done
}

# The newest version whose required extensions all exist.
php_newest_supported_version() {
    local version
    for version in $(php_available_versions | sort -rV); do
        if php_version_complete "$version"; then
            printf '%s\n' "$version"
            return 0
        fi
    done
    return 1
}

php_installed_versions() {
    local dir version
    for dir in "$MANDAL_PHP_DIR"/*/; do
        [[ -d "$dir" ]] || continue
        version="$(basename "$dir")"
        [[ "$version" =~ $MANDAL_PHP_VERSION_PATTERN ]] && printf '%s\n' "$version"
    done
}

php_version_installed() {
    package_installed "php$1-fpm"
}

php_write_dropins() {
    local version="$1" sapi
    for sapi in fpm cli; do
        write_file "$MANDAL_PHP_DIR/$version/$sapi/conf.d/99-mandal.ini" 0644 <<'EOF'
; managed-by: mandal
expose_php = Off
EOF
    done
    # FPM accepts [global] from an included file, so php-fpm.conf stays
    # untouched. With the default of 0 a reload kills workers at once and
    # resets every request in flight, which every deploy would do.
    write_file "$MANDAL_PHP_DIR/$version/fpm/pool.d/00-mandal-global.conf" 0644 <<'EOF'
; managed-by: mandal
[global]
process_control_timeout = 10s
EOF
}

php_install_version() {
    local version="$1" packages
    validate_php_version "$version"
    mapfile -t packages < <(php_packages_for "$version")
    [[ ${#packages[@]} -gt 0 ]] || die "no packages found for PHP $version"
    apt_install "${packages[@]}"
    php_write_dropins "$version"
    service_enable_now "php${version}-fpm"
    service_reload_or_restart "php${version}-fpm"
}

php_set_default() {
    local version="$1"
    run_cmd update-alternatives --set php "/usr/bin/php${version}"
    for tool in phar phar.phar phpize php-config; do
        if [[ -e "/usr/bin/${tool}${version}" ]]; then
            run_cmd update-alternatives --set "$tool" "/usr/bin/${tool}${version}"
        fi
    done
}
