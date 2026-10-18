#!/usr/bin/env bash
#
# Docker apps: one compose project per app under /srv/docker/<app>, bound
# to 127.0.0.1 only, with nginx in front. Docker publishes ports past ufw,
# which is why the loopback binding is not optional.

MANDAL_DOCKER_PORT_MIN=20000
MANDAL_DOCKER_PORT_MAX=29999

docker_app_dir() {
    printf '%s/%s\n' "$MANDAL_DOCKER_DIR" "$1"
}

validate_image() {
    [[ "$1" =~ ^[a-z0-9]([a-z0-9._/-]*[a-z0-9])?(:[A-Za-z0-9._-]{1,128})?(@sha256:[0-9a-f]{64})?$ ]] || die 2 "invalid image '$1'"
}

validate_container_port() {
    [[ "$1" =~ ^[0-9]{1,5}$ && "$1" -ge 1 && "$1" -le 65535 ]] || die 2 "invalid container port '$1'"
}

validate_env_pair() {
    [[ "$1" =~ ^[A-Z][A-Z0-9_]*=[^$'\n']*$ ]] || die 2 "invalid environment variable '$1' (KEY=value)"
}

# The next free loopback port for a new app, from the manifests.
docker_next_port() {
    local file used port
    used="$(for file in "$MANDAL_APPS_DIR"/*.conf; do [[ -f "$file" ]] && conf_get "$file" PORT 2> /dev/null; done | sort -n || true)"
    for port in $(seq "$MANDAL_DOCKER_PORT_MIN" "$MANDAL_DOCKER_PORT_MAX"); do
        grep -qx "$port" <<< "$used" || { printf '%s\n' "$port"; return 0; }
    done
    die "no free port for a Docker app"
}

# docker_render_compose name image container_port host_port
docker_render_compose() {
    local name="$1" image="$2" container_port="$3" host_port="$4"
    cat <<EOF
# managed-by: mandal app=${name}
services:
  app:
    image: ${image}
    restart: unless-stopped
    env_file:
      - .env
    ports:
      - "127.0.0.1:${host_port}:${container_port}"
    volumes:
      - data:/data
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

volumes:
  data:
EOF
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

docker_compose() {
    local name="$1"
    shift
    run_cmd docker compose --project-directory "$(docker_app_dir "$name")" --project-name "$name" "$@"
}

# docker_app_create name image container_port mode domain aliases env...
docker_app_create() {
    local name="$1" image="$2" container_port="$3" mode="$4" domain="$5" aliases="$6" host_port dir
    shift 6
    dir="$(docker_app_dir "$name")"
    host_port="$(docker_next_port)"
    run_cmd install -d -m 0750 "$dir"
    write_file "$dir/compose.yml" 0640 < <(docker_render_compose "$name" "$image" "$container_port" "$host_port")
    write_file "$dir/.env" 0600 < <(printf '%s\n' "$@")
    docker_write_upgrade_map
    # shellcheck disable=SC2086  # aliases are space separated by design
    write_file "$MANDAL_NGINX_DIR/sites-available/${name}.conf" 0644 < <(docker_render_proxy_vhost "$name" "$mode" "$host_port" "$domain" $aliases)
    run_cmd ln -sfn "$MANDAL_NGINX_DIR/sites-available/${name}.conf" "$MANDAL_NGINX_DIR/sites-enabled/${name}.conf"
    nginx_check_and_reload
    docker_compose "$name" pull --quiet
    docker_compose "$name" up -d --remove-orphans
    # shellcheck disable=SC2034  # read by app:create
    DOCKER_HOST_PORT="$host_port"
}

docker_app_deploy() {
    local name="$1"
    step "Pulling the latest image for $name"
    docker_compose "$name" pull --quiet
    step "Recreating the container"
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

# JSON describing the app's containers, for the inventory.
docker_app_status_json() {
    local name="$1"
    if ! has_cmd docker || [[ "$(unit_state docker)" != "active" ]] || ! is_root; then
        echo '[]'
        return 0
    fi
    { docker compose --project-directory "$(docker_app_dir "$name")" --project-name "$name" ps --format json 2> /dev/null || true; } | jq -s 'map(if type == "array" then .[] else . end) | map({name: .Name, image: .Image, state: .State, status: .Status})'
}
