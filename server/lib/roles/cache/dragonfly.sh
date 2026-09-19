#!/usr/bin/env bash
#
# Cache role: Dragonfly (Redis compatible).

cache_driver() {
    echo dragonfly
}

cache_installed() {
    has_cmd dragonfly || [[ -x /usr/local/bin/dragonfly ]] || package_installed dragonfly
}

cache_version() {
    local binary
    binary="$(command -v dragonfly 2> /dev/null || echo /usr/local/bin/dragonfly)"
    [[ -x "$binary" ]] || return 0
    "$binary" --version 2> /dev/null | grep -oE 'v?[0-9]+\.[0-9]+\.[0-9]+' | head -n 1 | tr -d 'v'
}

# JSON: {driver, installed, active, version, port}
cache_status() {
    local installed=false version=""
    if cache_installed; then
        installed=true
        version="$(cache_version || true)"
    fi
    jq -n --arg driver "$(cache_driver)" --argjson installed "$installed" --arg active "$(unit_state dragonfly)" --arg version "$version" \
        '{driver: $driver, installed: $installed, active: $active, version: $version, port: 6379}'
}
