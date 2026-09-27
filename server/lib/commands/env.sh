#!/usr/bin/env bash
#
# app:env - read or replace an app's shared .env file.

MANDAL_COMMANDS+=("app:env|Print an app's .env, or replace it with --from=<file>|write")

cmd_app_env() {
    local from="" arg home target
    deploy_parse_name "$@"
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --from=*) from="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    app_exists "$DEPLOY_NAME" || die "app '$DEPLOY_NAME' does not exist"
    home="$(app_home "$DEPLOY_NAME")"
    target="$home/shared/.env"

    if [[ -z "$from" ]]; then
        [[ -f "$target" ]] || die "no .env for app '$DEPLOY_NAME'"
        cat "$target"
        return 0
    fi

    [[ -f "$from" && ! -L "$from" ]] || die 2 "file not found: $from"
    if [[ "$(tr -d '\000' < "$from" | wc -c)" -ne "$(wc -c < "$from")" ]]; then
        die 2 "the new .env contains binary data"
    fi
    [[ "$(wc -c < "$from")" -le 262144 ]] || die 2 "the new .env is larger than 256 KB"

    with_lock
    audit "app:env $DEPLOY_NAME write"
    write_file "$target" 0600 "$DEPLOY_NAME:$DEPLOY_NAME" < "$from"
    dry_run || rm -f "$from"
    printf 'Updated %s. Restart queue workers or redeploy for the change to reach running processes.\n' "$target"
}
