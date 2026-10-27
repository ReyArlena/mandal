#!/usr/bin/env bash
#
# Docker apps: one compose project per app under /srv/docker/<app>, from a
# compose file the user provides. Optionally nginx proxies a domain to a
# port a service publishes on 127.0.0.1. Docker publishes ports past ufw,
# so anything else in `ports:` is reachable from the internet; the firewall
# page lists those.

MANDAL_DOCKER_COMPOSE_MAX_BYTES=262144

docker_app_dir() {
    printf '%s/%s\n' "$MANDAL_DOCKER_DIR" "$1"
}

validate_env_pair() {
    [[ "$1" =~ ^[A-Z][A-Z0-9_]*=[^$'\n']*$ ]] || die 2 "invalid environment variable '$1' (KEY=value)"
}

validate_proxy_port() {
    [[ "$1" =~ ^[0-9]{1,5}$ && "$1" -ge 1 && "$1" -le 65535 ]] || die 2 "invalid port '$1'"
}

# The compose file must be text of a sane size; compose itself checks the rest.
validate_compose_file() {
    local file="$1"
    [[ -f "$file" && ! -L "$file" ]] || die 2 "compose file not found: $file"
    [[ -s "$file" ]] || die 2 "the compose file is empty"
    if [[ "$(tr -d '\000' < "$file" | wc -c)" -ne "$(wc -c < "$file")" ]]; then
        die 2 "the compose file contains binary data"
    fi
    [[ "$(wc -c < "$file")" -le "$MANDAL_DOCKER_COMPOSE_MAX_BYTES" ]] || die 2 "the compose file is larger than 256 KB"
}

# docker_render_proxy_vhost name mode host_port domain [alias...]
docker_render_proxy_vhost() {
    local name="$1" mode="$2" host_port="$3" primary redirects target
    shift 3
    primary="$(app_primary_hostnames "$mode" "$@" | tr '\n' ' ')"
    primary="${primary% }"
    redirects="$(app_redirect_hostnames "$mode" "$@" | tr '\n' ' ')"
    redirects="${redirects% }"
    target="$(app_primary_hostnames "$mode" "$1")"

    printf '# managed-by: mandal app=%s\n' "$name"
    if [[ -n "$redirects" ]]; then
        cat <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${redirects};
    server_tokens off;
    return 301 \$scheme://${target}\$request_uri;
}

EOF
    fi
    cat <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${primary};
    server_tokens off;

    access_log /var/log/nginx/${name}-access.log;
    error_log /var/log/nginx/${name}-error.log;

    location / {
        proxy_pass http://127.0.0.1:${host_port};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_read_timeout 300s;
    }
}
EOF
}

# Shared map for websocket upgrades, written once into conf.d.
docker_write_upgrade_map() {
    write_file "$MANDAL_NGINX_DIR/conf.d/mandal-upgrade.conf" 0644 <<'EOF'
# managed-by: mandal
map $http_upgrade $connection_upgrade {
    default upgrade;
    '' close;
}
EOF
}

# Commands that change the project. Progress is printed as plain lines so
# a long image pull shows what it is doing over SSH instead of a redrawn
# terminal display or nothing at all.
docker_compose() {
    local name="$1"
    shift
    run_cmd docker compose --progress plain --project-directory "$(docker_app_dir "$name")" --project-name "$name" "$@"
}

# The resolved compose configuration as JSON, or an error from compose.
docker_compose_config() {
    local name="$1"
    docker compose --project-directory "$(docker_app_dir "$name")" --project-name "$name" config --format json 2>&1
}

# The ports the project publishes, from the compose file:
# [{host_ip, published, target, protocol}]
docker_app_ports_json() {
    local name="$1" config
    if ! has_cmd docker || dry_run; then
        echo '[]'
        return 0
    fi
    config="$(docker_compose_config "$name")" || { echo '[]'; return 0; }
    jq '[.services // {} | .[] | .ports // [] | .[] | {host_ip: (.host_ip // "0.0.0.0"), published: ((.published // "") | tostring), target: ((.target // "") | tostring), protocol: (.protocol // "tcp")}]' <<< "$config" 2> /dev/null || echo '[]'
}

# Validates the compose file in the project directory and, with a proxy,
# that a service publishes the proxied port on loopback. Dies on failure,
# so the caller must restore any previous file first if it wants to keep it.
docker_check_project() {
    local name="$1" proxy="$2" port="$3" config
    has_cmd docker || dry_run || die "Docker is not installed; run mandal provision first"
    dry_run && return 0
    config="$(docker_compose_config "$name")" || die "the compose file is invalid: $(printf '%s' "$config" | tail -n 3)"
    if [[ "$proxy" == true ]]; then
        jq -e --arg port "$port" '[.services // {} | .[] | .ports // [] | .[] | select((.host_ip // "") == "127.0.0.1" and ((.published // "") | tostring) == $port)] | length > 0' <<< "$config" > /dev/null \
            || die "no service publishes 127.0.0.1:$port, which nginx would proxy to; add \"127.0.0.1:$port:<container port>\" to a service's ports"
    fi
}

# Ports published to every interface by running containers, which ufw
# does not filter: [{container, host, port, target, protocol}]
docker_exposed_ports_json() {
    if ! has_cmd docker || [[ "$(unit_state docker)" != "active" ]] || ! is_root; then
        echo '[]'
        return 0
    fi
    { docker ps --format '{{json .}}' 2> /dev/null || true; } | jq -s '
        [.[] | . as $c | (.Ports // "") | split(", ") | .[]
            | capture("^(?<host>[^:]+|\\[[^\\]]+\\]):(?<port>[0-9-]+)->(?<target>[0-9-]+)/(?<protocol>tcp|udp|sctp)$")?
            | select(.host != "127.0.0.1" and .host != "[::1]")
            | {container: $c.Names, host: .host, port: .port, target: .target, protocol: .protocol}]
        | unique_by([.port, .protocol])
    ' 2> /dev/null || echo '[]'
}

# docker_write_proxy name mode port domain aliases
docker_write_proxy() {
    local name="$1" mode="$2" port="$3" domain="$4" aliases="$5"
    docker_write_upgrade_map
    # shellcheck disable=SC2086  # aliases are space separated by design
    write_file "$MANDAL_NGINX_DIR/sites-available/${name}.conf" 0644 < <(docker_render_proxy_vhost "$name" "$mode" "$port" "$domain" $aliases)
    run_cmd ln -sfn "$MANDAL_NGINX_DIR/sites-available/${name}.conf" "$MANDAL_NGINX_DIR/sites-enabled/${name}.conf"
    nginx_check_and_reload
}

# docker_app_create name compose_file proxy port mode domain aliases env...
docker_app_create() {
    local name="$1" compose="$2" proxy="$3" port="$4" mode="$5" domain="$6" aliases="$7" dir
    shift 7
    dir="$(docker_app_dir "$name")"
    run_cmd install -d -m 0750 "$dir"
    write_file "$dir/.env" 0600 < <(printf '%s\n' "$@")
    write_file "$dir/compose.yml" 0640 < "$compose"
    # The check dies on failure, so it runs in a subshell and the files
    # written so far are removed before this process dies too.
    if ! (docker_check_project "$name" "$proxy" "$port"); then
        dry_run || rm -rf "$dir"
        die "app '$name' was not created"
    fi
    if [[ "$proxy" == true ]]; then
        docker_write_proxy "$name" "$mode" "$port" "$domain" "$aliases"
    fi
    docker_compose "$name" pull
    docker_compose "$name" up -d --remove-orphans
}

# docker_app_replace_compose name compose_file: validates the new file and
# recreates the containers; the previous file is kept when it is invalid.
docker_app_replace_compose() {
    local name="$1" compose="$2" dir manifest proxy port previous loopback new_port=""
    dir="$(docker_app_dir "$name")"
    manifest="$(app_conf_path "$name")"
    proxy="$(conf_get "$manifest" PROXY 2> /dev/null || echo false)"
    port="$(conf_get "$manifest" PORT 2> /dev/null || true)"
    previous="$(cat "$dir/compose.yml" 2> /dev/null || true)"
    write_file "$dir/compose.yml" 0640 < "$compose"

    # When the port nginx proxies to has moved and the new file publishes
    # exactly one loopback port, nginx follows it. With several, the user
    # has to say which.
    if [[ "$proxy" == true ]] && ! dry_run; then
        loopback="$(docker_app_ports_json "$name" | jq -r '[.[] | select(.host_ip == "127.0.0.1") | .published] | unique | .[]')"
        if [[ -n "$loopback" ]] && ! grep -qx "$port" <<< "$loopback"; then
            if [[ "$(wc -l <<< "$loopback")" -eq 1 ]]; then
                new_port="$loopback"
            else
                [[ -z "$previous" ]] || printf '%s\n' "$previous" > "$dir/compose.yml"
                die "the compose file no longer publishes 127.0.0.1:$port for nginx and publishes several loopback ports ($(tr '\n' ' ' <<< "$loopback" | sed 's/ $//')); the previous compose file was kept. Please change the port using the Upstream port button, then apply the file again."
            fi
        fi
    fi

    if ! dry_run && [[ -n "$previous" ]]; then
        if ! (docker_check_project "$name" "$proxy" "${new_port:-$port}"); then
            printf '%s\n' "$previous" > "$dir/compose.yml"
            die "the previous compose file was kept"
        fi
    else
        docker_check_project "$name" "$proxy" "${new_port:-$port}"
    fi
    docker_compose "$name" up -d --remove-orphans
    if [[ -n "$new_port" ]]; then
        step "Following the proxied port"
        docker_change_proxy_port "$name" "$new_port"
        printf 'nginx now proxies %s to 127.0.0.1:%s.\n' "$name" "$new_port"
    fi
}

# docker_change_proxy_port name port: points nginx at another loopback port
# the compose file publishes. Only the proxy_pass line changes, so what
# certbot added to the vhost stays.
docker_change_proxy_port() {
    local name="$1" port="$2" manifest vhost old
    manifest="$(app_conf_path "$name")"
    [[ "$(conf_get "$manifest" TYPE 2> /dev/null || echo laravel)" == "docker" ]] || die 2 "--port only applies to Docker apps"
    [[ "$(conf_get "$manifest" PROXY 2> /dev/null || echo false)" == "true" ]] || die 2 "app '$name' is not served through nginx"
    validate_proxy_port "$port"
    old="$(conf_get "$manifest" PORT 2> /dev/null || true)"
    vhost="$MANDAL_NGINX_DIR/sites-available/${name}.conf"
    [[ -f "$vhost" ]] || dry_run || die "no nginx site for app '$name'"
    docker_check_project "$name" true "$port"
    conf_set "$manifest" PORT "$port"
    printf '+ proxy_pass 127.0.0.1:%s -> 127.0.0.1:%s in %s\n' "${old:-?}" "$port" "$vhost"
    if ! dry_run; then
        backup_file "$vhost"
        sed -i "s|proxy_pass http://127.0.0.1:${old};|proxy_pass http://127.0.0.1:${port};|" "$vhost"
        grep -q "proxy_pass http://127.0.0.1:${port};" "$vhost" || die "could not find the proxy_pass line in $vhost"
    fi
    nginx_check_and_reload
}

docker_app_deploy() {
    local name="$1"
    step "Pulling the latest images for $name"
    docker_compose "$name" pull
    step "Recreating the containers"
    docker_compose "$name" up -d --remove-orphans
    docker_compose "$name" ps
    printf '\nDeployed %s.\n' "$name"
}

docker_app_delete() {
    local name="$1" keep_data="$2"
    if [[ "$keep_data" == true ]]; then
        docker_compose "$name" down --remove-orphans
    else
        docker_compose "$name" down --volumes --remove-orphans
    fi
    run_cmd rm -f "$MANDAL_NGINX_DIR/sites-enabled/${name}.conf" "$MANDAL_NGINX_DIR/sites-available/${name}.conf"
    nginx_check_and_reload
    run_cmd rm -rf "$(docker_app_dir "$name")"
}

# Whether any container of the project is running (false without Docker).
docker_app_running() {
    has_cmd docker || return 1
    [[ -n "$(docker compose --project-directory "$(docker_app_dir "$1")" --project-name "$1" ps -q --status running 2> /dev/null || true)" ]]
}

# JSON describing the app's containers, for the inventory.
docker_app_status_json() {
    local name="$1"
    if ! has_cmd docker || [[ "$(unit_state docker)" != "active" ]] || ! is_root; then
        echo '[]'
        return 0
    fi
    { docker compose --project-directory "$(docker_app_dir "$name")" --project-name "$name" ps --format json 2> /dev/null || true; } | jq -s 'map(if type == "array" then .[] else . end) | map({name: .Name, image: .Image, state: .State, status: .Status})'
}
