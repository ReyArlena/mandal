#!/usr/bin/env bash
#
# app:control - start, stop or restart a Docker app's containers. Stopping
# keeps the containers and their state, so starting again is quick; it is
# `compose stop`, not `compose down`.

MANDAL_COMMANDS+=("app:control|Start, stop or restart a Docker app (--name= --action=start|stop|restart)|write")

cmd_app_control() {
    local action="" arg
    deploy_parse_name "$@"
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --action=*) action="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$action" =~ ^(start|stop|restart)$ ]] || die 64 "--action must be start, stop or restart"
    require_root
    app_exists "$DEPLOY_NAME" || die "app '$DEPLOY_NAME' does not exist"
    [[ "$(conf_get "$(app_conf_path "$DEPLOY_NAME")" TYPE 2> /dev/null || echo laravel)" == "docker" ]] || die 2 "app '$DEPLOY_NAME' is not a Docker app"
    has_cmd docker || dry_run || die "Docker is not installed"
    if ! dry_run; then
        with_lock
        audit "app:control $DEPLOY_NAME $action"
    fi
    case "$action" in
        start)
            step "Starting $DEPLOY_NAME"
            docker_compose "$DEPLOY_NAME" up -d
            ;;
        stop)
            step "Stopping $DEPLOY_NAME (waiting for the containers to shut down cleanly)"
            docker_compose "$DEPLOY_NAME" stop
            ;;
        restart)
            step "Restarting $DEPLOY_NAME"
            docker_compose "$DEPLOY_NAME" restart
            ;;
    esac
    docker_compose "$DEPLOY_NAME" ps
    printf '\n%s: %s done.\n' "$DEPLOY_NAME" "$action"
}
