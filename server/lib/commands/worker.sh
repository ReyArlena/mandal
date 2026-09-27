#!/usr/bin/env bash
#
# worker:add / worker:remove / worker:restart / worker:list - Supervisor
# programs that run an app's artisan commands (queue workers, Horizon,
# Reverb) as the app user.

MANDAL_COMMANDS+=("worker:add|Add a Supervisor program running an artisan command for an app|write")
MANDAL_COMMANDS+=("worker:remove|Remove an app's Supervisor program|write")
MANDAL_COMMANDS+=("worker:restart|Restart an app's Supervisor programs|write")
MANDAL_COMMANDS+=("worker:list|List an app's Supervisor programs as JSON|read")

MANDAL_WORKER_NAME_PATTERN='^[a-z][a-z0-9-]{0,31}$'
# Artisan arguments only: letters, digits and the punctuation options use.
MANDAL_WORKER_COMMAND_PATTERN='^[A-Za-z0-9:_=,. -]{1,200}$'

worker_program() {
    printf '%s-%s\n' "$1" "$2"
}

worker_conf_path() {
    printf '%s/%s.conf\n' "$MANDAL_SUPERVISOR_DIR" "$(worker_program "$1" "$2")"
}

validate_worker_name() {
    [[ "$1" =~ $MANDAL_WORKER_NAME_PATTERN ]] || die 2 "invalid worker name '$1'"
}

validate_worker_command() {
    [[ "$1" =~ $MANDAL_WORKER_COMMAND_PATTERN ]] || die 2 "invalid command '$1': artisan arguments only"
}

# worker_render app worker php command processes
worker_render() {
    local app="$1" worker="$2" php="$3" command="$4" processes="$5" program home
    program="$(worker_program "$app" "$worker")"
    home="$(app_home "$app")"
    cat <<EOF
# managed-by: mandal app=${app}
[program:${program}]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php${php} ${home}/current/artisan ${command}
directory=${home}/current
user=${app}
numprocs=${processes}
autostart=true
autorestart=true
startsecs=1
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/supervisor/${program}.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
environment=HOME="${home}",USER="${app}",PATH="${home}/.local/bin:/usr/local/bin:/usr/bin:/bin"
EOF
}

worker_apply() {
    if has_cmd supervisorctl || dry_run; then
        run_cmd supervisorctl reread
        run_cmd supervisorctl update
    fi
}

worker_parse() {
    local arg
    WORKER_APP=""
    WORKER_NAME=""
    for arg in "$@"; do
        case "$arg" in
            --app=*) WORKER_APP="${arg#*=}" ;;
            --name=*) WORKER_NAME="${arg#*=}" ;;
        esac
    done
    [[ -n "$WORKER_APP" ]] || die 64 "--app is required"
    validate_name "$WORKER_APP"
    [[ -z "$WORKER_NAME" ]] || validate_worker_name "$WORKER_NAME"
}

cmd_worker_add() {
    local command="queue:work --sleep=3 --tries=3 --max-time=3600" processes=1 arg php
    worker_parse "$@"
    [[ -n "$WORKER_NAME" ]] || die 64 "--name is required"
    for arg in "$@"; do
        case "$arg" in
            --app=* | --name=*) ;;
            --command=*) command="${arg#*=}" ;;
            --processes=*) processes="${arg#*=}" ;;
            --horizon) command="horizon"; processes=1 ;;
            --reverb) command="reverb:start" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    validate_worker_command "$command"
    [[ "$processes" =~ ^[1-9][0-9]?$ ]] || die 2 "--processes must be between 1 and 99"
    require_root
    app_exists "$WORKER_APP" || die "app '$WORKER_APP' does not exist"
    php="$(conf_get "$(app_conf_path "$WORKER_APP")" PHP)" || die "app '$WORKER_APP' has no PHP version"
    if ! dry_run; then
        with_lock
        audit "worker:add $WORKER_APP $WORKER_NAME"
    fi
    step "Adding worker $WORKER_NAME to $WORKER_APP"
    run_cmd install -d -m 0755 /var/log/supervisor
    write_file "$(worker_conf_path "$WORKER_APP" "$WORKER_NAME")" 0644 < <(worker_render "$WORKER_APP" "$WORKER_NAME" "$php" "$command" "$processes")
    worker_apply
    printf '\nWorker %s added (%s x %s).\n' "$(worker_program "$WORKER_APP" "$WORKER_NAME")" "$processes" "$command"
}

cmd_worker_remove() {
    local arg file
    worker_parse "$@"
    [[ -n "$WORKER_NAME" ]] || die 64 "--name is required"
    for arg in "$@"; do
        case "$arg" in
            --app=* | --name=*) ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    file="$(worker_conf_path "$WORKER_APP" "$WORKER_NAME")"
    [[ -f "$file" ]] || dry_run || die "worker '$WORKER_NAME' does not exist for app '$WORKER_APP'"
    if ! dry_run; then
        with_lock
        audit "worker:remove $WORKER_APP $WORKER_NAME"
    fi
    step "Removing worker $WORKER_NAME from $WORKER_APP"
    if has_cmd supervisorctl || dry_run; then
        run_cmd supervisorctl stop "$(worker_program "$WORKER_APP" "$WORKER_NAME"):*" || true
    fi
    run_cmd rm -f "$file"
    worker_apply
    printf '\nWorker removed.\n'
}

cmd_worker_restart() {
    local arg
    worker_parse "$@"
    for arg in "$@"; do
        case "$arg" in
            --app=* | --name=*) ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    if ! dry_run; then
        with_lock
        audit "worker:restart $WORKER_APP ${WORKER_NAME:-all}"
    fi
    if [[ -n "$WORKER_NAME" ]]; then
        run_cmd supervisorctl restart "$(worker_program "$WORKER_APP" "$WORKER_NAME"):*"
    else
        local file
        for file in "$MANDAL_SUPERVISOR_DIR/${WORKER_APP}"-*.conf; do
            [[ -f "$file" ]] || continue
            run_cmd supervisorctl restart "$(basename "$file" .conf):*"
        done
    fi
    printf 'Restarted.\n'
}

cmd_worker_list() {
    worker_parse "$@"
    inv_supervisor | jq --arg app "$WORKER_APP" '
        .programs
        | map(select(.managed_app == $app))
        | map(. + {worker: (.name | ltrimstr($app + "-"))})
    '
}
