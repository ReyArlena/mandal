#!/usr/bin/env bash
#
# Updates: pending apt packages, component versions against their latest
# releases, reboot state and the unattended-upgrades result.

MANDAL_UPDATES_CACHE="${MANDAL_UPDATES_CACHE:-/var/cache/mandal}"
MANDAL_UPDATES_CACHE_TTL="${MANDAL_UPDATES_CACHE_TTL:-21600}"
MANDAL_UNATTENDED_LOG="${MANDAL_UNATTENDED_LOG:-/var/log/unattended-upgrades/unattended-upgrades.log}"
MANDAL_APT_HISTORY="${MANDAL_APT_HISTORY:-/var/log/apt/history.log}"
MANDAL_BOOT_DIR="${MANDAL_BOOT_DIR:-/boot}"
MANDAL_RELEASE_UPGRADES_CACHE="${MANDAL_RELEASE_UPGRADES_CACHE:-/var/lib/update-notifier/release-upgrade-available}"

# Pending apt updates as TSV: package, current, candidate, security(true|false)
updates_pending_tsv() {
    has_cmd apt-get || return 0
    { apt-get --just-print -o Debug::NoLocking=true upgrade 2> /dev/null || true; } | awk '
        /^Inst / {
            name = $2
            current = $3; gsub(/[\[\]]/, "", current)
            candidate = $4; sub(/^\(/, "", candidate)
            security = ($0 ~ /-security|Debian-Security|Ubuntu:[^ ]*-security/) ? "true" : "false"
            printf "%s\t%s\t%s\t%s\n", name, current, candidate, security
        }
    '
}

updates_held_tsv() {
    has_cmd apt-mark || return 0
    { apt-mark showhold 2> /dev/null || true; } | awk '{printf "%s\n", $1}'
}

# A fetch that is cached in /var/cache/mandal for MANDAL_UPDATES_CACHE_TTL seconds.
updates_cached_fetch() {
    local key="$1" url="$2" age=0
    local file="$MANDAL_UPDATES_CACHE/$key"
    if [[ -f "$file" ]]; then
        age=$(( $(date +%s) - $(stat -c %Y "$file") ))
        if [[ "$age" -lt "$MANDAL_UPDATES_CACHE_TTL" ]]; then
            cat "$file"
            return 0
        fi
    fi
    mkdir -p "$MANDAL_UPDATES_CACHE"
    if curl -fsSL --max-time 10 -o "$file.tmp" "$url" 2> /dev/null; then
        mv -f "$file.tmp" "$file"
        cat "$file"
    else
        rm -f "$file.tmp"
        [[ -f "$file" ]] && cat "$file"
    fi
}

# The tag name of the latest GitHub release of owner/repo, without a leading v.
updates_github_latest() {
    updates_cached_fetch "github-${1//\//-}.json" "https://api.github.com/repos/$1/releases/latest" | { jq -r '.tag_name // empty' 2> /dev/null || true; } | sed 's/^v//'
}

updates_composer_latest() {
    updates_cached_fetch "composer-latest.txt" "https://getcomposer.org/versions" | { jq -r '.stable[0].version // empty' 2> /dev/null || true; }
}

# Component rows as TSV: id, name, installed, latest, update_available, note
updates_components_tsv() {
    local installed latest
    installed="$(package_version nginx | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+' || true)"
    printf 'nginx\tnginx\t%s\t\tfalse\tUpdated through apt\n' "$installed"
    for v in $(php_installed_versions); do
        printf 'php%s\tPHP %s\t%s\t\tfalse\tPatch releases arrive through automatic updates\n' "$v" "$v" "$(package_version "php${v}-cli" | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+' || true)"
    done
    installed="$(db_status | jq -r '.version')"
    printf 'database\t%s\t%s\t\tfalse\tUpdated through apt\n' "$(db_driver)" "$installed"
    installed="$(cache_status | jq -r '.version')"
    latest="$(updates_github_latest dragonflydb/dragonfly)"
    printf 'cache\t%s\t%s\t%s\t%s\tRestart drops the cache and interrupts queues briefly\n' "$(cache_driver)" "$installed" "$latest" "$(updates_newer "$installed" "$latest")"
    installed="$(js_status | jq -r '.version')"
    latest="$(updates_github_latest oven-sh/bun)"
    printf 'js\t%s\t%s\t%s\t%s\t\n' "$(js_driver)" "$installed" "$latest" "$(updates_newer "$installed" "$latest")"
    installed="$(composer --version 2> /dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | awk 'NR == 1' || true)"
    latest="$(updates_composer_latest)"
    printf 'composer\tComposer\t%s\t%s\t%s\t\n' "$installed" "$latest" "$(updates_newer "$installed" "$latest")"
    installed="$(docker --version 2> /dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | awk 'NR == 1' || true)"
    latest="$(apt-cache policy docker-ce 2> /dev/null | awk '/Candidate:/ {print $2}' | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | awk 'NR == 1' || true)"
    printf 'docker\tDocker\t%s\t%s\t%s\tUpdating restarts every container\n' "$installed" "$latest" "$(updates_newer "$installed" "$latest")"
    printf 'supervisor\tSupervisor\t%s\t\tfalse\tUpdated through apt\n' "$(package_version supervisor | grep -oE '^[0-9]+\.[0-9]+(\.[0-9]+)?' || true)"
    printf 'mandal\tmandal CLI\t%s\t\tfalse\tUpdated from the panel\n' "$(mandal_version)"
}

# true when the latest version sorts after the installed one.
updates_newer() {
    local installed="$1" latest="$2"
    if [[ -z "$installed" || -z "$latest" ]]; then
        echo false
        return 0
    fi
    if [[ "$(printf '%s\n%s\n' "$installed" "$latest" | sort -V | tail -n 1)" != "$installed" ]]; then
        echo true
    else
        echo false
    fi
}

updates_kernel_json() {
    local running newest
    running="$(uname -r 2> /dev/null || true)"
    newest="$(find "$MANDAL_BOOT_DIR" -maxdepth 1 -name 'vmlinuz-*' -printf '%f\n' 2> /dev/null | sed 's/^vmlinuz-//' | sort -V | tail -n 1)"
    jq -n --arg running "$running" --arg newest "${newest:-$running}" '{running: $running, newest: $newest, newer_installed: ($newest != "" and $newest != $running)}'
}

# The last unattended-upgrades run: time and whether anything failed.
updates_unattended_json() {
    local last="" status="unknown"
    if [[ -r "$MANDAL_UNATTENDED_LOG" ]]; then
        last="$({ grep -E 'INFO (Starting unattended upgrades|All upgrades installed|No packages found|Packages that will be upgraded)' "$MANDAL_UNATTENDED_LOG" || true; } | tail -n 1)"
        if { grep -E 'ERROR|WARNING' "$MANDAL_UNATTENDED_LOG" || true; } | tail -n 1 | grep -q . && [[ "$(grep -nE 'ERROR' "$MANDAL_UNATTENDED_LOG" | tail -n 1 | cut -d: -f1)" -gt "$(grep -nE 'All upgrades installed|No packages found' "$MANDAL_UNATTENDED_LOG" | tail -n 1 | cut -d: -f1 || echo 0)" ]]; then
            status="failed"
        elif [[ -n "$last" ]]; then
            status="ok"
        fi
    fi
    jq -n --arg last "$last" --arg status "$status" '{last: (if $last == "" then null else ($last | split(",")[0]) end), status: $status}'
}

updates_history_json() {
    [[ -r "$MANDAL_APT_HISTORY" ]] || { echo '[]'; return 0; }
    awk '
        /^Start-Date:/ { sub(/^Start-Date: +/, ""); start = $0 }
        /^(Upgrade|Install|Remove):/ { action = $1; sub(/^[A-Za-z]+: +/, ""); packages = $0 }
        /^End-Date:/ { if (start != "") printf "%s\t%s\t%s\n", start, action, packages; start = ""; action = ""; packages = "" }
    ' "$MANDAL_APT_HISTORY" | tail -n 20 | json_tsv at action packages | jq 'map(.action |= rtrimstr(":")) | reverse'
}

updates_release_upgrade() {
    if [[ -r "$MANDAL_RELEASE_UPGRADES_CACHE" ]]; then
        { grep -oE '[0-9]{2}\.[0-9]{2}( LTS)?' "$MANDAL_RELEASE_UPGRADES_CACHE" || true; } | awk 'NR == 1'
    fi
}

updates_status_json() {
    local pending held components
    pending="$(updates_pending_tsv | json_tsv package current candidate security | jq 'map(.security |= (. == "true"))')"
    held="$(updates_held_tsv | json_lines)"
    components="$(updates_components_tsv | json_tsv id name installed latest update_available note | jq 'map(.update_available |= (. == "true"))')"
    jq -n \
        --argjson system "$(inv_system)" \
        --argjson kernel "$(updates_kernel_json)" \
        --argjson pending "$pending" \
        --argjson held "$held" \
        --argjson components "$components" \
        --argjson unattended "$(updates_unattended_json)" \
        --argjson history "$(updates_history_json)" \
        --arg release "$(updates_release_upgrade)" \
        '{
            os: $system.os,
            uptime_seconds: $system.uptime_seconds,
            reboot_required: $system.reboot_required,
            reboot_packages: $system.reboot_packages,
            kernel: $kernel,
            pending: {security: ($pending | map(select(.security))), other: ($pending | map(select(.security | not))), held: $held},
            components: $components,
            unattended: $unattended,
            history: $history,
            release_upgrade: (if $release == "" then null else $release end)
        }'
}

# ---------------------------------------------------------------------------
# Applying
# ---------------------------------------------------------------------------

updates_apply_security() {
    apt_update --force
    if has_cmd unattended-upgrade; then
        run_cmd unattended-upgrade -v
    else
        run_cmd apt-get upgrade "${APT_OPTIONS[@]}"
    fi
}

updates_apply_all() {
    apt_update --force
    run_cmd apt-get dist-upgrade "${APT_OPTIONS[@]}"
    run_cmd apt-get autoremove "${APT_OPTIONS[@]}"
}

updates_apply_package() {
    local package="$1"
    [[ "$package" =~ ^[a-z0-9][a-z0-9+.-]{0,99}$ ]] || die 2 "invalid package name '$package'"
    apt_update --force
    run_cmd apt-get install --only-upgrade "${APT_OPTIONS[@]}" "$package"
}

# After apt changed things, make sure the services we manage are still sane.
updates_after_apt() {
    if has_cmd nginx; then
        if dry_run; then
            printf '+ nginx -t && systemctl reload nginx\n'
        elif nginx -t > /dev/null 2>&1; then
            service_reload_or_restart nginx
        else
            warn "nginx configuration test failed after the update; nginx was not reloaded"
        fi
    fi
    local v
    for v in $(php_installed_versions); do
        service_reload_or_restart "php${v}-fpm"
    done
}

component_update() {
    local id="$1"
    case "$id" in
        cache)
            step "Updating $(cache_driver)"
            cache_upgrade
            ;;
        js)
            step "Updating $(js_driver)"
            js_upgrade
            ;;
        composer)
            step "Updating Composer"
            run_cmd composer self-update --no-interaction
            ;;
        docker)
            step "Updating Docker"
            apt_update --force
            run_cmd apt-get install --only-upgrade "${APT_OPTIONS[@]}" docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
            ;;
        *) die 2 "unknown component '$id' (cache, js, composer or docker)" ;;
    esac
}
