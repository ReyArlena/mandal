#!/usr/bin/env bash
#
# JavaScript tooling role: Bun, installed system-wide as /usr/local/bin/bun
# from the official GitHub release archive.

MANDAL_BUN_RELEASES="https://github.com/oven-sh/bun/releases"

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

js_release_target() {
    case "$(cpu_arch)" in
        x86_64) echo linux-x64 ;;
        aarch64 | arm64) echo linux-aarch64 ;;
        *) die "unsupported architecture for Bun: $(cpu_arch)" ;;
    esac
}

js_install() {
    local target zip dir
    if js_installed; then
        printf '+ bun already installed (%s)\n' "$(js_version)"
        return 0
    fi
    apt_install unzip
    target="$(js_release_target)"
    download "${MANDAL_BUN_RELEASES}/latest/download/bun-${target}.zip" .zip
    zip="$DOWNLOADED_FILE"
    dir="$(mktemp -d)"
    run_cmd unzip -q -o "$zip" -d "$dir"
    run_cmd install -m 0755 "${dir}/bun-${target}/bun" /usr/local/bin/bun
    run_cmd ln -sf /usr/local/bin/bun /usr/local/bin/bunx
    dry_run || rm -rf "$zip" "$dir"
}
