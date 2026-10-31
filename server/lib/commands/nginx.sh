#!/usr/bin/env bash
#
# nginx:limits - settings nginx applies to every site.

MANDAL_COMMANDS+=("nginx:limits|Change nginx's request body limit for every site (--client-max-body-size=1g)|write")

cmd_nginx_limits() {
    local arg size=""
    for arg in "$@"; do
        case "$arg" in
            --client-max-body-size=*) size="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$size" ]] || die 64 "--client-max-body-size is required"
    size="${size,,}"
    [[ "$size" =~ ^[1-9][0-9]{0,5}[kmg]?$ ]] || die 2 "--client-max-body-size must be a size such as 512m or 1g"
    require_root
    has_cmd nginx || dry_run || die "nginx is not installed; run mandal provision first"
    nginx_conf_sets client_max_body_size && die 2 "nginx.conf sets client_max_body_size itself; change it there"
    if ! dry_run; then
        with_lock
        audit "nginx:limits client_max_body_size=$size"
        conf_set "$MANDAL_SERVER_CONF" NGINX_CLIENT_MAX_BODY_SIZE "$size"
    fi
    MANDAL_NGINX_BODY_SIZE_DEFAULT="$size" nginx_write_mandal_conf
    nginx_check_and_reload
    printf 'nginx accepts request bodies up to %s.\n' "$size"
}
