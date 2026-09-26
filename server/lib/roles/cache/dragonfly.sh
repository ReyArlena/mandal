#!/usr/bin/env bash
#
# Cache role: Dragonfly (Redis compatible).
#
# Installed from the official .deb, which ships /usr/bin/dragonfly, a
# systemd unit running as the dfly user, and a flag file at
# /etc/dragonfly/dragonfly.conf (one --flag=value per line).

MANDAL_DRAGONFLY_CONF="${MANDAL_DRAGONFLY_CONF:-/etc/dragonfly/dragonfly.conf}"
MANDAL_DRAGONFLY_RELEASES="https://github.com/dragonflydb/dragonfly/releases"

cache_driver() {
    echo dragonfly
}

cache_installed() {
    has_cmd dragonfly || [[ -x /usr/bin/dragonfly ]] || package_installed dragonfly
}

cache_version() {
    local binary
    binary="$(command -v dragonfly 2> /dev/null || echo /usr/bin/dragonfly)"
    [[ -x "$binary" ]] || return 0
    "$binary" --version 2> /dev/null | grep -oE 'v?[0-9]+\.[0-9]+\.[0-9]+' | awk 'NR == 1' | tr -d 'v'
}

# JSON: {driver, installed, active, version, port}
cache_status() {
    local installed=false version=""
    if cache_installed; then
        installed=true
        version="$(cache_status_version)"
    fi
    jq -n --arg driver "$(cache_driver)" --argjson installed "$installed" --arg active "$(unit_state dragonfly)" --arg version "$version" \
        '{driver: $driver, installed: $installed, active: $active, version: $version, port: 6379}'
}

cache_status_version() {
    cache_version || true
}

cache_deb_arch() {
    case "$(cpu_arch)" in
        x86_64) echo amd64 ;;
        aarch64 | arm64) echo arm64 ;;
        *) die "unsupported architecture for Dragonfly: $(cpu_arch)" ;;
    esac
}

# The password apps use, created once and kept in /etc/mandal/cache.conf.
# Left in CACHE_PASSWORD.
cache_password() {
    local file="${MANDAL_ETC}/cache.conf"
    CACHE_PASSWORD="$(conf_get "$file" PASSWORD 2> /dev/null || true)"
    if [[ -z "$CACHE_PASSWORD" ]]; then
        CACHE_PASSWORD="$(random_secret 40)"
        write_file "$file" 0600 <<EOF
# managed-by: mandal
DRIVER=dragonfly
HOST=127.0.0.1
PORT=6379
PASSWORD=${CACHE_PASSWORD}
EOF
    fi
}

cache_install() {
    local deb
    if cache_installed; then
        printf '+ dragonfly already installed (%s)\n' "$(cache_status_version)"
    else
        download "${MANDAL_DRAGONFLY_RELEASES}/latest/download/dragonfly_$(cache_deb_arch).deb" .deb
        deb="$DOWNLOADED_FILE"
        run_cmd apt-get install "${APT_OPTIONS[@]}" "$deb"
        dry_run || rm -f "$deb"
    fi
    cache_configure
    service_enable_now dragonfly
    service_restart dragonfly
}

# Add our flags to the flag file, leaving whatever the package or the
# administrator put there in place.
cache_configure() {
    local password
    cache_password
    password="$CACHE_PASSWORD"
    ensure_line "$MANDAL_DRAGONFLY_CONF" "--bind=127.0.0.1"
    ensure_line "$MANDAL_DRAGONFLY_CONF" "--default_lua_flags=allow-undeclared-keys"
    if [[ -f "$MANDAL_DRAGONFLY_CONF" ]] && grep -q '^--requirepass=' "$MANDAL_DRAGONFLY_CONF"; then
        printf '+ requirepass already set in %s\n' "$MANDAL_DRAGONFLY_CONF"
    else
        ensure_line "$MANDAL_DRAGONFLY_CONF" "--requirepass=${password}"
    fi
    if ! dry_run && [[ -f "$MANDAL_DRAGONFLY_CONF" ]]; then
        chmod 0640 "$MANDAL_DRAGONFLY_CONF"
        chown dfly:dfly "$MANDAL_DRAGONFLY_CONF" 2> /dev/null || true
    fi
}
