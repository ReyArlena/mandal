#!/usr/bin/env bash
#
# JavaScript tooling role: Bun.

js_driver() {
    echo bun
}

js_binary() {
    command -v bun 2> /dev/null || echo /usr/local/bin/bun
}

js_installed() {
    [[ -x "$(js_binary)" ]]
}

js_version() {
    js_installed || return 0
    "$(js_binary)" --version 2> /dev/null | head -n 1
}

# JSON: {driver, installed, version, path}
js_status() {
    local installed=false version="" path=""
    if js_installed; then
        installed=true
        version="$(js_version || true)"
        path="$(js_binary)"
    fi
    jq -n --arg driver "$(js_driver)" --argjson installed "$installed" --arg version "$version" --arg path "$path" \
        '{driver: $driver, installed: $installed, version: $version, path: $path}'
}
