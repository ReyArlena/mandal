#!/usr/bin/env bash
#
# inventory - read everything the panel shows, straight from the server.
#
# Each collector prints one JSON value and never fails: when a tool is not
# installed it reports that rather than erroring, so a partly provisioned
# or foreign server can still be inspected.

MANDAL_COMMANDS+=("inventory|Show everything on this server as JSON|read")
MANDAL_COMMANDS+=("app:list|List apps and their manifests as JSON|read")

cmd_inventory() {
    load_all_drivers
    jq -n \
        --argjson mandal "$(inv_mandal)" \
        --argjson system "$(inv_system)" \
        --argjson services "$(inv_services)" \
        --argjson php "$(inv_php)" \
        --argjson nginx "$(inv_nginx)" \
        --argjson database "$(inv_database)" \
        --argjson cache "$(inv_cache)" \
        --argjson js "$(inv_js)" \
        --argjson supervisor "$(inv_supervisor)" \
        --argjson certificates "$(inv_certificates)" \
        --argjson firewall "$(inv_firewall)" \
        --argjson fail2ban "$(inv_fail2ban)" \
        --argjson docker "$(inv_docker)" \
        --argjson apps "$(inv_apps)" \
        --argjson backups "$(inv_backups)" \
        '$ARGS.named'
}

cmd_app_list() {
    inv_apps
}

# ---------------------------------------------------------------------------
# Mandal and system
# ---------------------------------------------------------------------------

inv_mandal() {
    local provisioned=false
    [[ -n "$(conf_get "$MANDAL_SERVER_CONF" PROVISIONED_AT 2> /dev/null || true)" ]] && provisioned=true
    jq -n \
        --arg version "$(mandal_version)" \
        --arg build "$(mandal_build_hash)" \
        --argjson provisioned "$provisioned" \
        --argjson settings "$(conf_read "$MANDAL_SERVER_CONF")" \
        --arg database "$(driver_for database)" \
        --arg cache "$(driver_for cache)" \
        --arg js "$(driver_for js)" \
        --arg panel "$(panel_apps | awk 'NR == 1')" \
        '{
            version: $version,
            build: $build,
            provisioned: $provisioned,
            hosts_panel: ($panel != ""),
            panel_app: (if $panel == "" then null else $panel end),
            provisioned_at: ($settings.provisioned_at // null),
            drivers: {database: $database, cache: $cache, js: $js}
        }'
}

os_field() {
    conf_get "$MANDAL_OS_RELEASE" "$1" 2> /dev/null || true
}

inv_system() {
    local uptime=0 mem_total=0 mem_available=0 disk_total=0 disk_used=0 reboot=false packages='[]'
    [[ -r /proc/uptime ]] && uptime="$(cut -d. -f1 /proc/uptime)"
    if [[ -r /proc/meminfo ]]; then
        mem_total="$(awk '/^MemTotal:/ {print $2 * 1024}' /proc/meminfo)"
        mem_available="$(awk '/^MemAvailable:/ {print $2 * 1024}' /proc/meminfo)"
    fi
    if has_cmd df; then
        read -r disk_total disk_used < <(df -P -B1 / 2> /dev/null | awk 'NR == 2 {print $2, $3}') || true
    fi
    if [[ -f "$MANDAL_REBOOT_REQUIRED" ]]; then
        reboot=true
        [[ -r "${MANDAL_REBOOT_REQUIRED}.pkgs" ]] && packages="$(json_lines < "${MANDAL_REBOOT_REQUIRED}.pkgs")"
    fi
    jq -n \
        --arg hostname "$(hostname 2> /dev/null || echo unknown)" \
        --arg os_id "$(os_field ID)" \
        --arg os_version "$(os_field VERSION_ID)" \
        --arg os_name "$(os_field PRETTY_NAME)" \
        --arg kernel "$(uname -r 2> /dev/null || echo unknown)" \
        --arg arch "$(uname -m 2> /dev/null || echo unknown)" \
        --argjson cpus "$(nproc 2> /dev/null || echo 0)" \
        --argjson uptime "${uptime:-0}" \
        --argjson mem_total "${mem_total:-0}" \
        --argjson mem_available "${mem_available:-0}" \
        --argjson disk_total "${disk_total:-0}" \
        --argjson disk_used "${disk_used:-0}" \
        --argjson reboot "$reboot" \
        --argjson packages "$packages" \
        '{
            hostname: $hostname,
            os: {id: $os_id, version: $os_version, name: $os_name},
            kernel: $kernel,
            arch: $arch,
            cpus: $cpus,
            uptime_seconds: $uptime,
            memory: {total_bytes: $mem_total, available_bytes: $mem_available},
            disk: {total_bytes: $disk_total, used_bytes: $disk_used},
            reboot_required: $reboot,
            reboot_packages: $packages
        }'
}

inv_services() {
    local unit units=(nginx mariadb dragonfly supervisor docker fail2ban ufw cron ssh unattended-upgrades)
    local dir
    for dir in "$MANDAL_PHP_DIR"/*/; do
        [[ -d "$dir" ]] || continue
        units+=("php$(basename "$dir")-fpm")
    done
    for unit in "${units[@]}"; do
        if unit_exists "$unit"; then
            printf '%s\t%s\t%s\n' "$unit" true "$(unit_state "$unit")"
        else
            printf '%s\t%s\t%s\n' "$unit" false "absent"
        fi
    done | json_tsv unit installed state | jq 'map(.installed |= (. == "true"))'
}

# ---------------------------------------------------------------------------
# PHP
# ---------------------------------------------------------------------------

php_default_version() {
    local target
    target="$(readlink -f /etc/alternatives/php 2> /dev/null || true)"
    if [[ "$target" =~ php([0-9]+\.[0-9]+)$ ]]; then
        printf '%s\n' "${BASH_REMATCH[1]}"
    elif has_cmd php; then
        php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2> /dev/null || true
    fi
}

# Parse every pool file of a PHP version into TSV: name, user, listen, pm, managed app, file
php_pools_tsv() {
    local version="$1" file
    for file in "$MANDAL_PHP_DIR/$version/fpm/pool.d"/*.conf; do
        [[ -f "$file" ]] || continue
        awk -v file="$file" '
            function flush() {
                if (name != "") printf "%s\t%s\t%s\t%s\t%s\t%s\n", name, user, listen, pm, managed, file
                name = ""; user = ""; listen = ""; pm = ""
            }
            /^[[:space:]]*[#;][[:space:]]*managed-by:[[:space:]]*mandal/ {
                if (match($0, /app=[A-Za-z0-9._-]+/)) managed = substr($0, RSTART + 4, RLENGTH - 4)
            }
            /^[[:space:]]*\[[^]]+\][[:space:]]*$/ {
                flush()
                gsub(/^[[:space:]]*\[|\][[:space:]]*$/, "")
                name = $0
            }
            /^[[:space:]]*user[[:space:]]*=/ { sub(/^[^=]*=[[:space:]]*/, ""); user = $0 }
            /^[[:space:]]*listen[[:space:]]*=/ { sub(/^[^=]*=[[:space:]]*/, ""); listen = $0 }
            /^[[:space:]]*pm[[:space:]]*=/ { sub(/^[^=]*=[[:space:]]*/, ""); pm = $0 }
            END { flush() }
        ' "$file"
    done
}

inv_php() {
    local dir version versions=() pools='[]' pool_json
    for dir in "$MANDAL_PHP_DIR"/*/; do
        [[ -d "$dir" ]] || continue
        version="$(basename "$dir")"
        [[ "$version" =~ $MANDAL_PHP_VERSION_PATTERN ]] || continue
        versions+=("$version")
    done
    for version in "${versions[@]}"; do
        pool_json="$(php_pools_tsv "$version" | json_tsv name user listen pm managed_app file | jq --arg version "$version" 'map(. + {php: $version} | .managed_app |= (if . == "" then null else . end))')"
        pools="$(jq -n --argjson a "$pools" --argjson b "$pool_json" '$a + $b')"
    done
    jq -n \
        --argjson versions "$(printf '%s\n' "${versions[@]:-}" | json_lines)" \
        --arg default "$(php_default_version)" \
        --argjson pools "$pools" \
        '{versions: $versions, default: (if $default == "" then null else $default end), pools: $pools}'
}

# ---------------------------------------------------------------------------
# nginx
# ---------------------------------------------------------------------------

# Parse one vhost file into TSV: managed app, server names, listen ports, root, redirects, has ssl
nginx_site_tsv() {
    awk '
        /^[[:space:]]*[#;][[:space:]]*managed-by:[[:space:]]*mandal/ {
            if (match($0, /app=[A-Za-z0-9._-]+/)) managed = substr($0, RSTART + 4, RLENGTH - 4)
        }
        /^[[:space:]]*server_name[[:space:]]/ {
            sub(/;.*$/, ""); sub(/^[[:space:]]*server_name[[:space:]]+/, "")
            for (i = 1; i <= NF; i++) if ($i != "_" && !(($i) in seen)) { seen[$i] = 1; names = names (names == "" ? "" : " ") $i }
        }
        /^[[:space:]]*listen[[:space:]]/ {
            sub(/;.*$/, ""); sub(/^[[:space:]]*listen[[:space:]]+/, "")
            if ($0 ~ /ssl/) ssl = "true"
            port = $1
            sub(/^.*:/, "", port)
            if (port ~ /^[0-9]+$/ && !((port) in ports)) { ports[port] = 1; listens = listens (listens == "" ? "" : " ") port }
        }
        /^[[:space:]]*root[[:space:]]/ { sub(/;.*$/, ""); root = $2 }
        /^[[:space:]]*return[[:space:]]+30[1278]/ { redirects++ }
        END { printf "%s\t%s\t%s\t%s\t%d\t%s\n", managed, names, listens, root, redirects, (ssl == "true" ? "true" : "false") }
    ' "$1"
}

inv_nginx() {
    local installed=false version="" config_ok=null file name enabled sites='[]' row
    if has_cmd nginx || package_installed nginx; then
        installed=true
        version="$(nginx -v 2>&1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | awk 'NR == 1' || true)"
        if has_cmd nginx && is_root; then
            if nginx -t > /dev/null 2>&1; then config_ok=true; else config_ok=false; fi
        fi
    fi
    for file in "$MANDAL_NGINX_DIR/sites-available"/*; do
        [[ -f "$file" ]] || continue
        name="$(basename "$file")"
        enabled=false
        [[ -e "$MANDAL_NGINX_DIR/sites-enabled/$name" ]] && enabled=true
        row="$(nginx_site_tsv "$file" | json_tsv managed_app server_names ports root redirects ssl | jq --arg name "$name" --arg file "$file" --argjson enabled "$enabled" '
            .[0] | . + {
                name: $name,
                file: $file,
                enabled: $enabled,
                managed_app: (if .managed_app == "" then null else .managed_app end),
                server_names: (.server_names | split(" ") | map(select(length > 0))),
                ports: (.ports | split(" ") | map(select(length > 0) | tonumber)),
                redirects: (.redirects | tonumber),
                ssl: (.ssl == "true")
            }')"
        sites="$(jq -n --argjson a "$sites" --argjson b "$row" '$a + [$b]')"
    done
    jq -n --argjson installed "$installed" --arg version "$version" --argjson config_ok "$config_ok" --argjson sites "$sites" \
        '{installed: $installed, version: $version, config_ok: $config_ok, sites: $sites}'
}

# ---------------------------------------------------------------------------
# Role drivers
# ---------------------------------------------------------------------------

inv_database() {
    jq -n --argjson status "$(db_status)" --argjson data "$(db_list)" '$status + $data'
}

inv_cache() {
    cache_status
}

inv_js() {
    js_status
}

# ---------------------------------------------------------------------------
# Supervisor
# ---------------------------------------------------------------------------

supervisor_programs_tsv() {
    local file
    for file in "$MANDAL_SUPERVISOR_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        awk -v file="$file" '
            function flush() {
                if (name != "") printf "%s\t%s\t%s\t%s\t%s\t%s\n", name, command, user, numprocs, managed, file
                name = ""; command = ""; user = ""; numprocs = "1"
            }
            /^[[:space:]]*[#;][[:space:]]*managed-by:[[:space:]]*mandal/ {
                if (match($0, /app=[A-Za-z0-9._-]+/)) managed = substr($0, RSTART + 4, RLENGTH - 4)
            }
            /^[[:space:]]*\[program:[^]]+\][[:space:]]*$/ {
                flush()
                gsub(/^[[:space:]]*\[program:|\][[:space:]]*$/, "")
                name = $0
            }
            /^[[:space:]]*command[[:space:]]*=/ { sub(/^[^=]*=[[:space:]]*/, ""); command = $0 }
            /^[[:space:]]*user[[:space:]]*=/ { sub(/^[^=]*=[[:space:]]*/, ""); user = $0 }
            /^[[:space:]]*numprocs[[:space:]]*=/ { sub(/^[^=]*=[[:space:]]*/, ""); numprocs = $0 }
            END { flush() }
        ' "$file"
    done
}

inv_supervisor() {
    local installed=false programs status='[]'
    if has_cmd supervisorctl || package_installed supervisor; then
        installed=true
    fi
    programs="$(supervisor_programs_tsv | json_tsv name command user numprocs managed_app file | jq 'map(.numprocs |= tonumber | .managed_app |= (if . == "" then null else . end))')"
    if [[ "$installed" == true ]] && is_root; then
        status="$({ supervisorctl status 2> /dev/null || true; } | awk '{print $1 "\t" $2}' | json_tsv process state)"
    fi
    jq -n --argjson installed "$installed" --arg active "$(unit_state supervisor)" --argjson programs "$programs" --argjson status "$status" \
        '{installed: $installed, active: $active, programs: $programs, processes: $status}'
}

# ---------------------------------------------------------------------------
# Certificates (certbot)
# ---------------------------------------------------------------------------

certbot_tsv() {
    awk '
        function flush() {
            if (name != "") printf "%s\t%s\t%s\t%s\t%s\n", name, domains, expiry, status, path
            name = ""; domains = ""; expiry = ""; status = ""; path = ""
        }
        /Certificate Name:/ { flush(); sub(/.*Certificate Name:[[:space:]]*/, ""); name = $0 }
        /Domains:/ { sub(/.*Domains:[[:space:]]*/, ""); domains = $0 }
        /Expiry Date:/ {
            if (match($0, /[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9:]+(\+[0-9:]+)?/)) expiry = substr($0, RSTART, RLENGTH)
            if (match($0, /\((VALID|INVALID|EXPIRED)[^)]*\)/)) status = substr($0, RSTART + 1, RLENGTH - 2)
        }
        /Certificate Path:/ { sub(/.*Certificate Path:[[:space:]]*/, ""); path = $0 }
        END { flush() }
    '
}

inv_certificates() {
    local installed=false certificates='[]'
    if has_cmd certbot; then
        installed=true
        if is_root; then
            certificates="$({ certbot certificates 2> /dev/null || true; } | certbot_tsv | json_tsv name domains expiry status path | jq 'map(.domains |= (split(" ") | map(select(length > 0))))')"
        fi
    fi
    jq -n --argjson installed "$installed" --argjson certificates "$certificates" '{installed: $installed, certificates: $certificates}'
}

# ---------------------------------------------------------------------------
# Firewall (ufw) and fail2ban
# ---------------------------------------------------------------------------

inv_firewall() {
    local installed=false status="unknown" incoming="" outgoing="" rules='[]' verbose
    if has_cmd ufw; then
        installed=true
        if is_root; then
            verbose="$(ufw status verbose 2> /dev/null || true)"
            status="$(printf '%s\n' "$verbose" | awk -F': ' '/^Status:/ {print $2}')"
            incoming="$(printf '%s\n' "$verbose" | grep -oE '[a-z]+ \(incoming\)' | awk '{print $1}' || true)"
            outgoing="$(printf '%s\n' "$verbose" | grep -oE '[a-z]+ \(outgoing\)' | awk '{print $1}' || true)"
            rules="$({ ufw show added 2> /dev/null || true; } | { grep -E '^ufw ' || true; } | sed 's/^ufw //' | json_lines)"
        fi
    fi
    jq -n --argjson installed "$installed" --arg status "${status:-unknown}" --arg incoming "$incoming" --arg outgoing "$outgoing" --argjson rules "$rules" \
        '{installed: $installed, status: $status, default_incoming: $incoming, default_outgoing: $outgoing, rules: $rules}'
}

inv_fail2ban() {
    local installed=false jails='[]' jail list banned rows=""
    if has_cmd fail2ban-client; then
        installed=true
        if is_root; then
            list="$(fail2ban-client status 2> /dev/null | awk -F':' '/Jail list:/ {print $2}' | tr ',' ' ' || true)"
            for jail in $list; do
                banned="$(fail2ban-client status "$jail" 2> /dev/null | awk -F':' '/Currently banned:/ {gsub(/[[:space:]]/, "", $2); print $2}' || echo 0)"
                rows+="${jail}	${banned:-0}"$'\n'
            done
            jails="$(printf '%s' "$rows" | json_tsv name banned | jq 'map(.banned |= tonumber)')"
        fi
    fi
    jq -n --argjson installed "$installed" --arg active "$(unit_state fail2ban)" --argjson jails "$jails" '{installed: $installed, active: $active, jails: $jails}'
}

# ---------------------------------------------------------------------------
# Docker
# ---------------------------------------------------------------------------

inv_docker() {
    local installed=false version="" projects='[]' containers='[]'
    if has_cmd docker; then
        installed=true
        version="$(docker --version 2> /dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | awk 'NR == 1' || true)"
        if [[ "$(unit_state docker)" == "active" ]] && is_root; then
            projects="$(docker compose ls --all --format json 2> /dev/null || true)"
            [[ -n "$projects" ]] && jq -e . <<< "$projects" > /dev/null 2>&1 || projects='[]'
            containers="$({ docker ps --all --format '{{json .}}' 2> /dev/null || true; } | jq -s '.')"
        fi
    fi
    jq -n --argjson installed "$installed" --arg active "$(unit_state docker)" --arg version "$version" --argjson projects "$projects" --argjson containers "$containers" \
        '{installed: $installed, active: $active, version: $version, projects: $projects, containers: $containers}'
}

# ---------------------------------------------------------------------------
# Backups
# ---------------------------------------------------------------------------

inv_backups() {
    backup_status_json
}

# ---------------------------------------------------------------------------
# Apps (manifests in /etc/mandal/apps)
# ---------------------------------------------------------------------------

inv_app() {
    local name="$1" home manifest current="" releases=0 deploy_key="" user_exists=false
    manifest="$(conf_read "$(app_conf_path "$name")")"
    home="$MANDAL_HOME_DIR/$name"
    if id "$name" > /dev/null 2>&1; then
        user_exists=true
    fi
    if [[ -L "$home/current" ]]; then
        current="$(basename "$(readlink -f "$home/current")")"
    fi
    if [[ -d "$home/releases" ]]; then
        releases="$(find "$home/releases" -mindepth 1 -maxdepth 1 -type d 2> /dev/null | wc -l)"
    fi
    [[ -r "$home/.ssh/id_ed25519.pub" ]] && deploy_key="$(cat "$home/.ssh/id_ed25519.pub")"
    local containers='[]'
    if [[ "$(jq -r '.type // "laravel"' <<< "$manifest")" == "docker" ]]; then
        containers="$(docker_app_status_json "$name")"
        home="$(docker_app_dir "$name")"
    fi
    jq -n --arg name "$name" --argjson manifest "$manifest" --arg home "$home" --argjson user_exists "$user_exists" \
        --arg current "$current" --argjson releases "$releases" --arg deploy_key "$deploy_key" --argjson containers "$containers" '
        {name: $name, home: $home, user_exists: $user_exists}
        + $manifest
        + {current_release: (if $current == "" then null else $current end), releases: $releases, deploy_key: (if $deploy_key == "" then null else $deploy_key end), containers: $containers}
    '
}

inv_apps() {
    local file name apps='[]'
    for file in "$MANDAL_APPS_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        name="$(basename "$file" .conf)"
        [[ "$name" =~ $MANDAL_NAME_PATTERN ]] || continue
        apps="$(jq -n --argjson a "$apps" --argjson b "$(inv_app "$name")" '$a + [$b]')"
    done
    printf '%s\n' "$apps"
}
