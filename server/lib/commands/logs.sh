#!/usr/bin/env bash
#
# logs:sources / logs:read

MANDAL_COMMANDS+=("logs:sources|List readable log sources as JSON (--app=<name> to filter)|read")
MANDAL_COMMANDS+=("logs:read|Read a log source as JSON (--source=<id> [--lines=] [--grep=] [--since=] [--level=] [--after=])|read")

cmd_logs_sources() {
    local arg app=""
    for arg in "$@"; do
        case "$arg" in
            --app=*) app="${arg#*=}"; validate_name "$app" ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    logs_sources_json "$app"
}

cmd_logs_read() {
    local arg source="" lines=200 pattern="" since="" level="" after=""
    for arg in "$@"; do
        case "$arg" in
            --source=*) source="${arg#*=}" ;;
            --lines=*) lines="${arg#*=}" ;;
            --grep=*) pattern="${arg#*=}" ;;
            --since=*) since="${arg#*=}" ;;
            --level=*) level="${arg#*=}" ;;
            --after=*) after="${arg#*=}" ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$source" ]] || die 64 "--source is required"
    [[ "$lines" =~ ^[1-9][0-9]{0,3}$ && "$lines" -le "$MANDAL_LOGS_MAX_LINES" ]] || die 2 "--lines must be between 1 and $MANDAL_LOGS_MAX_LINES"
    [[ -z "$since" || "$since" =~ ^[0-9]{1,4}[smhd]$|^[0-9]{4}-[0-9]{2}-[0-9]{2}([ T][0-9:]{5,8})?$ ]] || die 2 "invalid --since (use 30m, 2h, 1d or a date)"
    [[ -z "$level" || "$level" =~ ^[A-Za-z]{1,10}$ ]] || die 2 "invalid --level"
    local cursor_pattern='^[A-Za-z0-9=;:_.-]{1,200}$'
    [[ -z "$after" || "$after" =~ $cursor_pattern ]] || die 2 "invalid --after cursor"
    [[ "${#pattern}" -le 200 && "$pattern" != *$'\n'* ]] || die 2 "invalid --grep"
    if [[ "$since" =~ ^[0-9]{1,4}[smhd]$ ]]; then
        local amount="${since%?}" unit="${since: -1}"
        case "$unit" in
            s) since="${amount} seconds ago" ;;
            m) since="${amount} minutes ago" ;;
            h) since="${amount} hours ago" ;;
            d) since="${amount} days ago" ;;
        esac
    fi
    require_root
    logs_read "$source" "$lines" "$pattern" "$since" "$level" "$after"
}
