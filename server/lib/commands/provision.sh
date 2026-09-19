#!/usr/bin/env bash
#
# provision --check - report what is on the server, what provisioning would
# add, and anything that would conflict. Changes nothing.
#
# Applying the provisioning plan is a separate step that is not available
# yet; until it is, this command only accepts --check.

MANDAL_COMMANDS+=("provision|Inspect a server before provisioning it (--check)|read")

MANDAL_SUPPORTED_OS_ID="ubuntu"
MANDAL_SUPPORTED_OS_VERSIONS=("24.04")

cmd_provision() {
    local check=false arg
    for arg in "$@"; do
        case "$arg" in
            --check) check=true ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$check" == true ]] || die 64 "provisioning is not available yet; run 'mandal provision --check' to inspect the server"
    require_root
    load_all_drivers
    provision_check
}

# component name installed version action note
component_row() {
    printf '%s\t%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" "$5"
}

apt_component() {
    local name="$1" package="$2" note="${3:-}"
    if package_installed "$package"; then
        component_row "$name" true "$(package_version "$package")" keep "$note"
    else
        component_row "$name" false "" install "$note"
    fi
}

provision_components() {
    local status
    apt_component nginx nginx
    apt_component supervisor supervisor
    apt_component certbot python3-certbot-nginx
    apt_component ufw ufw
    apt_component fail2ban fail2ban
    apt_component unattended-upgrades unattended-upgrades
    apt_component git git
    apt_component jq jq
    apt_component acl acl
    apt_component rclone rclone
    apt_component zstd zstd

    status="$(db_status)"
    if [[ "$(jq -r '.installed' <<< "$status")" == true ]]; then
        component_row "database ($(db_driver))" true "$(jq -r '.version' <<< "$status")" keep ""
    else
        component_row "database ($(db_driver))" false "" install ""
    fi

    status="$(cache_status)"
    if [[ "$(jq -r '.installed' <<< "$status")" == true ]]; then
        component_row "cache ($(cache_driver))" true "$(jq -r '.version' <<< "$status")" keep ""
    else
        component_row "cache ($(cache_driver))" false "" install ""
    fi

    status="$(js_status)"
    if [[ "$(jq -r '.installed' <<< "$status")" == true ]]; then
        component_row "js ($(js_driver))" true "$(jq -r '.version' <<< "$status")" keep ""
    else
        component_row "js ($(js_driver))" false "" install ""
    fi

    if has_cmd docker; then
        component_row docker true "$(docker --version 2> /dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n 1 || true)" keep ""
    else
        component_row docker false "" install ""
    fi

    if has_cmd composer; then
        component_row composer true "$(composer --version 2> /dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n 1 || true)" keep ""
    else
        component_row composer false "" install ""
    fi

    local versions
    versions="$(jq -r '.versions | join(" ")' <<< "$(inv_php)")"
    if [[ -n "$versions" ]]; then
        component_row php true "$versions" keep "newest supported version will be added if missing"
    else
        component_row php false "" install "newest version with all required extensions"
    fi

    if [[ -r /proc/swaps ]] && [[ "$(wc -l < /proc/swaps)" -gt 1 ]]; then
        component_row swap true "" keep "existing swap is left alone"
    else
        component_row swap false "" install "/swapfile"
    fi
}

# Which process (if any) listens on a TCP port. Prints the process name.
port_listener() {
    has_cmd ss || return 0
    ss -H -ltnp "sport = :$1" 2> /dev/null | grep -oE 'users:\(\("[^"]+"' | head -n 1 | sed 's/users:(("//'
}

provision_conflicts() {
    local listener
    if package_installed apache2 || unit_exists apache2; then
        printf 'apache\tApache is installed; it competes with nginx for ports 80 and 443. Remove it or stop it before provisioning.\n'
    fi
    for port in 80 443; do
        listener="$(port_listener "$port")"
        if [[ -n "$listener" && "$listener" != "nginx" ]]; then
            printf 'port_%s\tPort %s is in use by %s, which is not nginx.\n' "$port" "$port" "$listener"
        fi
    done
    if [[ "$(unit_state firewalld)" == "active" ]]; then
        printf 'firewalld\tfirewalld is active; mandal manages the firewall with ufw. Disable firewalld first.\n'
    fi
    if [[ "$(db_driver)" == "mariadb" ]] && (package_installed mysql-server || package_installed percona-server-server); then
        printf 'mysql\tAnother MySQL-compatible server is installed; it conflicts with MariaDB.\n'
    fi
    listener="$(port_listener 3306)"
    if [[ -n "$listener" && "$listener" != mariadbd && "$listener" != mysqld ]]; then
        printf 'port_3306\tPort 3306 is in use by %s.\n' "$listener"
    fi
    listener="$(port_listener 6379)"
    if [[ -n "$listener" && "$listener" != "$(cache_driver)" ]]; then
        printf 'port_6379\tPort 6379 is in use by %s; the cache (%s) needs it.\n' "$listener" "$(cache_driver)"
    fi
}

provision_notes() {
    if has_cmd node; then
        printf 'Node.js is installed. It is left alone; deployments use %s.\n' "$(js_driver)"
    fi
    if has_cmd nft && [[ "$(unit_state nftables)" == "active" ]] && ! has_cmd ufw; then
        printf 'nftables is active without ufw. Existing rules are kept; ufw rules will be added alongside them.\n'
    fi
    if [[ -f "${MANDAL_SSHD_DROPIN_DIR}/50-cloud-init.conf" ]]; then
        printf 'A cloud-init SSH drop-in exists. mandal adds its own drop-in that takes precedence over it.\n'
    fi
}

ssh_setting() {
    sshd -T 2> /dev/null | awk -v key="$1" '$1 == key {print $2; exit}'
}

provision_ssh() {
    if ! has_cmd sshd; then
        echo '{"installed": false}'
        return 0
    fi
    jq -n \
        --arg port "$(ssh_setting port)" \
        --arg password "$(ssh_setting passwordauthentication)" \
        --arg root "$(ssh_setting permitrootlogin)" \
        --arg pubkey "$(ssh_setting pubkeyauthentication)" \
        '{
            installed: true,
            port: (if $port == "" then 22 else ($port | tonumber) end),
            password_authentication: $password,
            permit_root_login: $root,
            pubkey_authentication: $pubkey
        }'
}

provision_check() {
    local system os_supported=false os_id os_version components conflicts notes existing ssh

    system="$(inv_system)"
    os_id="$(jq -r '.os.id' <<< "$system")"
    os_version="$(jq -r '.os.version' <<< "$system")"
    for version in "${MANDAL_SUPPORTED_OS_VERSIONS[@]}"; do
        if [[ "$os_id" == "$MANDAL_SUPPORTED_OS_ID" && "$os_version" == "$version" ]]; then
            os_supported=true
        fi
    done

    components="$(provision_components | json_tsv name installed version action note | jq 'map(.installed |= (. == "true"))')"
    conflicts="$(provision_conflicts | json_tsv code message)"
    notes="$(provision_notes | json_lines)"
    ssh="$(provision_ssh)"
    existing="$(jq -n \
        --argjson nginx "$(inv_nginx)" \
        --argjson database "$(inv_database)" \
        --argjson firewall "$(inv_firewall)" \
        --argjson apps "$(inv_apps)" \
        '{
            sites: ($nginx.sites | length),
            databases: ($database.databases | length),
            firewall_rules: ($firewall.rules | length),
            firewall_status: $firewall.status,
            apps: ($apps | length)
        }')"

    jq -n \
        --argjson system "$system" \
        --argjson os_supported "$os_supported" \
        --argjson provisioned "$(jq '.provisioned' <<< "$(inv_mandal)")" \
        --argjson components "$components" \
        --argjson conflicts "$conflicts" \
        --argjson notes "$notes" \
        --argjson ssh "$ssh" \
        --argjson existing "$existing" \
        '{
            ok: ($os_supported and ($conflicts | length == 0)),
            os: ($system.os + {supported: $os_supported}),
            provisioned: $provisioned,
            components: $components,
            conflicts: $conflicts,
            notes: $notes,
            ssh: $ssh,
            existing: $existing
        }'
}
