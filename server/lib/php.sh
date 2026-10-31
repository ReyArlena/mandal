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

# The PHP-FPM limits Mandal manages per version in 99-mandal.ini, with their
# defaults: big enough for a 1 GB upload through the panel. The CLI keeps
# PHP's own (no time limit), so artisan and queue workers are unaffected.
MANDAL_PHP_LIMITS=(upload_max_filesize post_max_size memory_limit max_execution_time max_input_time)
declare -gA MANDAL_PHP_LIMIT_DEFAULTS=([upload_max_filesize]=1G [post_max_size]=1G [memory_limit]=256M [max_execution_time]=300 [max_input_time]=300)

# php_limit version key: the value in the version's FPM drop-in, or the default.
php_limit() {
    local value
    value="$(sed -nE "s/^$2[[:space:]]*=[[:space:]]*([^[:space:];]+).*/\1/p" "$MANDAL_PHP_DIR/$1/fpm/conf.d/99-mandal.ini" 2> /dev/null | tail -n 1)"
    printf '%s\n' "${value:-${MANDAL_PHP_LIMIT_DEFAULTS[$2]}}"
}

php_limits_json() {
    local key args=()
    for key in "${MANDAL_PHP_LIMITS[@]}"; do
        args+=(--arg "$key" "$(php_limit "$1" "$key")")
    done
    jq -n "${args[@]}" '$ARGS.named'
}

# A PHP size such as 1G, 512M or 8388608 in bytes; -1 (no limit) stays -1.
php_size_bytes() {
    awk -v s="$1" 'BEGIN {
        if (s == "-1") { print -1; exit }
        n = s + 0; u = toupper(substr(s, length(s)))
        if (u == "K") n *= 1024; else if (u == "M") n *= 1048576; else if (u == "G") n *= 1073741824
        printf "%.0f\n", n
    }'
}

validate_php_size() {
    [[ "$2" =~ ^[1-9][0-9]{0,5}[KMG]?$ || ("$1" == memory_limit && "$2" == "-1") ]] \
        || die 2 "$1 must be a size such as 512M or 1G"
}

validate_php_seconds() {
    [[ "$2" =~ ^[0-9]{1,6}$ ]] || die 2 "$1 must be a number of seconds (0 for no limit)"
}

# php_write_dropins version [key=value...]: rewrites the drop-ins, keeping
# each limit's current value unless a new one is given.
php_write_dropins() {
    local version="$1" key pair lines=""
    shift
    local -A limits=()
    for key in "${MANDAL_PHP_LIMITS[@]}"; do
        limits[$key]="$(php_limit "$version" "$key")"
    done
    for pair in "$@"; do
        limits[${pair%%=*}]="${pair#*=}"
    done
    for key in "${MANDAL_PHP_LIMITS[@]}"; do
        lines+="$key = ${limits[$key]}"$'\n'
    done
    write_file "$MANDAL_PHP_DIR/$version/fpm/conf.d/99-mandal.ini" 0644 <<EOF
; managed-by: mandal
expose_php = Off
${lines%$'\n'}
EOF
    write_file "$MANDAL_PHP_DIR/$version/cli/conf.d/99-mandal.ini" 0644 <<'EOF'
; managed-by: mandal
expose_php = Off
EOF
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
