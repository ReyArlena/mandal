#!/usr/bin/env bash
#
# app:compose - read or replace a Docker app's compose file. A new file is
# checked with `docker compose config` and the containers are recreated
# from it; an invalid file leaves the previous one in place.

MANDAL_COMMANDS+=("app:compose|Print a Docker app's compose file, or replace it with --from=<file>|write")

cmd_app_compose() {
    local from="" arg target
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
    [[ "$(conf_get "$(app_conf_path "$DEPLOY_NAME")" TYPE 2> /dev/null || echo laravel)" == "docker" ]] || die 2 "app '$DEPLOY_NAME' is not a Docker app"
    target="$(docker_app_dir "$DEPLOY_NAME")/compose.yml"

    if [[ -z "$from" ]]; then
        [[ -f "$target" ]] || die "no compose file for app '$DEPLOY_NAME'"
        cat "$target"
        return 0
    fi

    validate_compose_file "$from"
    if ! dry_run; then
        with_lock
        audit "app:compose $DEPLOY_NAME write"
    fi
    step "Applying the new compose file for $DEPLOY_NAME"
    docker_app_replace_compose "$DEPLOY_NAME" "$from"
    dry_run || rm -f "$from"
    printf '\nUpdated %s and recreated the containers.\n' "$target"
}
