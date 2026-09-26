#!/usr/bin/env bash
#
# provision --check   report what is on the server, what provisioning would
#                     add, and anything that would conflict. Changes nothing.
# provision           install and configure the stack. Idempotent; existing
#                     configuration is kept and extended through drop-ins.

MANDAL_COMMANDS+=("provision|Provision the server, or inspect it first with --check|write")

MANDAL_SUPPORTED_OS_ID="ubuntu"
MANDAL_SUPPORTED_OS_VERSIONS=("24.04")

cmd_provision() {
    local check=false arg
    for arg in "$@"; do
        case "$arg" in
            --check) check=true ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            --skip-ssh-hardening) export MANDAL_SKIP_SSH_HARDENING=1 ;;
            --php=*) export MANDAL_PHP_VERSION="${arg#*=}" ;;
            --database=* | --cache=* | --js=*) provision_set_driver "${arg#--}" ;;
            --help | -h) provision_usage; return 0 ;;
            *) die 64 "unknown option '$arg' (see mandal provision --help)" ;;
        esac
    done
    require_root
    load_all_drivers
    if [[ "$check" == true ]]; then
        provision_check
        return 0
    fi
    provision_apply
}

provision_usage() {
    cat <<'EOF'
Usage: mandal provision [--check] [options]

  --check                 Inspect the server and print a JSON report; change nothing
  --dry-run               Print what provisioning would do without doing it
  --database=<driver>     Database driver (default: mariadb)
  --cache=<driver>        Cache driver (default: dragonfly)
  --js=<driver>           JavaScript tooling driver (default: bun)
  --php=<version>         PHP version to install (default: newest with all extensions)
  --skip-ssh-hardening    Leave sshd configuration untouched
EOF
}

# Record the chosen driver for a role before it is loaded, e.g. database=mariadb.
provision_set_driver() {
    local role="${1%%=*}" driver="${1#*=}"
    [[ "$driver" =~ ^[a-z0-9]+$ ]] || die 64 "invalid ${role} driver '${driver}'"
    [[ -r "${MANDAL_ROOT}/lib/roles/${role}/${driver}.sh" ]] || die 64 "unknown ${role} driver '${driver}'"
    mkdir -p "$MANDAL_ETC"
    conf_set "$MANDAL_SERVER_CONF" "$(printf '%s' "$role" | tr '[:lower:]' '[:upper:]')" "$driver"
}

provision_apply() {
    local report conflicts os_supported log step_name
    report="$(provision_check)"
    os_supported="$(jq -r '.os.supported' <<< "$report")"
    conflicts="$(jq -r '.conflicts | map(.message) | join("\n")' <<< "$report")"
    [[ "$os_supported" == true ]] || die "unsupported operating system: $(jq -r '.os.name' <<< "$report") (Ubuntu ${MANDAL_SUPPORTED_OS_VERSIONS[*]} is supported)"
    if [[ -n "$conflicts" ]]; then
        printf 'error: provisioning cannot continue:\n%s\n' "$conflicts" >&2
        exit 1
    fi

    mkdir -p "$MANDAL_LOG_DIR"
    log="${MANDAL_LOG_DIR}/provision-$(date -u +%Y%m%dT%H%M%SZ).log"
    if dry_run; then
        log=/dev/null
    else
        with_lock
        audit "provision"
    fi

    {
        printf 'mandal %s provisioning %s (%s)\n' "$(mandal_version)" "$(hostname)" "$(jq -r '.os.name' <<< "$report")"
        printf 'drivers: database=%s cache=%s js=%s\n' "$(db_driver)" "$(cache_driver)" "$(js_driver)"
        for step_name in "${MANDAL_PROVISION_STEPS[@]}"; do
            "prov_${step_name}"
        done
        printf '\nProvisioning finished.\n'
    } 2>&1 | tee -a "$log"

    return "${PIPESTATUS[0]}"
}

# ---------------------------------------------------------------------------
# --check
# ---------------------------------------------------------------------------

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

    if swap_present; then
        component_row swap true "" keep "existing swap is left alone"
    else
        component_row swap false "" install "/swapfile"
    fi
}

# Which process (if any) listens on a TCP port. Prints the process name.
port_listener() {
    has_cmd ss || return 0
    ss -H -ltnp "sport = :$1" 2> /dev/null | grep -oE 'users:\(\("[^"]+"' | head -n 1 | sed -e 's/^users:(("//' -e 's/"$//'
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
    if [[ -L "${MANDAL_NGINX_DIR}/sites-enabled/default" ]]; then
        printf 'The stock nginx default site is enabled. It is disabled only if unmodified; otherwise it is kept.\n'
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
