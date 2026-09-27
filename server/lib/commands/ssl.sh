#!/usr/bin/env bash
#
# app:ssl - request a Let's Encrypt certificate for an app's host names
# with certbot's nginx plugin, after checking DNS points at this server.

MANDAL_COMMANDS+=("app:ssl|Request a Let's Encrypt certificate for an app|write")

# Every address this server answers on.
server_addresses() {
    { hostname -I 2> /dev/null || true; } | tr ' ' '\n' | grep -v '^$'
    if [[ -n "${MANDAL_PUBLIC_IP:-}" ]]; then
        printf '%s\n' "$MANDAL_PUBLIC_IP"
    fi
}

# The addresses a host name resolves to.
resolve_addresses() {
    { getent ahosts "$1" 2> /dev/null || true; } | awk '{print $1}' | sort -u
}

# Prints host names that do not resolve to this server, one per line.
ssl_dns_mismatches() {
    local host resolved ours
    ours="$(server_addresses | sort -u)"
    for host in "$@"; do
        resolved="$(resolve_addresses "$host")"
        if [[ -z "$resolved" ]] || ! grep -qxF -f <(printf '%s\n' "$ours") <<< "$resolved"; then
            printf '%s\n' "$host"
        fi
    done
}

cmd_app_ssl() {
    local email="" skip_dns=false arg manifest mode domain aliases hosts mismatches
    deploy_parse_name "$@"
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --email=*) email="${arg#*=}" ;;
            --skip-dns-check) skip_dns=true ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    app_exists "$DEPLOY_NAME" || die "app '$DEPLOY_NAME' does not exist"
    manifest="$(app_conf_path "$DEPLOY_NAME")"
    domain="$(conf_get "$manifest" DOMAIN)" || die "app '$DEPLOY_NAME' has no domain"
    mode="$(conf_get "$manifest" WWW 2> /dev/null || echo redirect-to-naked)"
    aliases="$(conf_get "$manifest" ALIASES 2> /dev/null || true)"
    [[ -n "$email" ]] || email="$(server_conf_get CERTBOT_EMAIL 2> /dev/null || true)"
    if [[ -n "$email" ]]; then
        [[ "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || die 2 "invalid email '$email'"
    fi

    # shellcheck disable=SC2086  # aliases are space separated by design
    mapfile -t hosts < <(app_hostnames "$mode" "$domain" $aliases)

    if [[ "$skip_dns" == false ]]; then
        mismatches="$(ssl_dns_mismatches "${hosts[@]}")"
        if [[ -n "$mismatches" ]]; then
            printf 'error: these host names do not point at this server yet:\n%s\n' "$mismatches" >&2
            printf 'Fix the DNS records and try again, or pass --skip-dns-check if the server is behind NAT.\n' >&2
            exit 3
        fi
    fi

    if ! dry_run; then
        with_lock
        audit "app:ssl $DEPLOY_NAME"
    fi

    local args=(--nginx --non-interactive --agree-tos --redirect --keep-until-expiring --expand)
    if [[ -n "$email" ]]; then
        args+=(--email "$email")
    else
        args+=(--register-unsafely-without-email)
    fi
    local host
    for host in "${hosts[@]}"; do
        args+=(-d "$host")
    done

    step "Requesting a certificate for ${hosts[*]}"
    run_cmd certbot "${args[@]}"
    nginx_check_and_reload
    dry_run || conf_set "$manifest" SSL "true"
    dry_run || conf_set "$manifest" SSL_AT "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf '\nCertificate installed for %s.\n' "$DEPLOY_NAME"
}
