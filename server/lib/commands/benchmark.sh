#!/usr/bin/env bash
#
# benchmark - how long the panel's reads take on this server: the CLI's own
# start, each inventory collector and every read command a panel page runs.
# Nothing is changed; the panel's Benchmark task adds the SSH side.

MANDAL_COMMANDS+=("benchmark|Time the CLI's start, the inventory collectors and the read commands the panel runs, as JSON|read")

# How many processes to start when measuring what one process start costs.
MANDAL_BENCH_SPAWNS="${MANDAL_BENCH_SPAWNS:-200}"

# Seconds since a start read from $EPOCHREALTIME, to the millisecond.
bench_since() {
    awk -v a="$1" -v b="$EPOCHREALTIME" 'BEGIN { printf "%.3f", b - a }'
}

# Starting a process is most of what the CLI spends its time on, and what
# makes a busy shared VPS slow.
bench_process_start() {
    local i start seconds bin
    bin="$(type -P true || echo /bin/true)"
    start=$EPOCHREALTIME
    for (( i = 0; i < MANDAL_BENCH_SPAWNS; i++ )); do
        "$bin"
    done
    seconds="$(bench_since "$start")"
    jq -n --argjson count "$MANDAL_BENCH_SPAWNS" --argjson seconds "$seconds" \
        '{count: $count, seconds: $seconds, per_process_ms: ((($seconds * 1000 / $count) * 100 | round) / 100)}'
}

# Each collector on its own, after the shared docker ps the inventory reads
# once for all of them: [{name, seconds}], slowest first.
bench_collectors() {
    local section start rows=()
    start=$EPOCHREALTIME
    local MANDAL_DOCKER_CONTAINERS
    # shellcheck disable=SC2034  # read by docker_containers_json in the collectors
    MANDAL_DOCKER_CONTAINERS="$(docker_containers_json)"
    rows+=("$(jq -n --argjson seconds "$(bench_since "$start")" '{name: "docker ps (shared)", seconds: $seconds}')")
    for section in "${MANDAL_INVENTORY_SECTIONS[@]}"; do
        start=$EPOCHREALTIME
        ( set +e; "inv_$section" > /dev/null 2>&1 )
        rows+=("$(jq -n --arg name "$section" --argjson seconds "$(bench_since "$start")" '{name: $name, seconds: $seconds}')")
    done
    printf '%s\n' "${rows[@]}" | jq -s 'sort_by(-.seconds)'
}

# bench_command arguments...
# Runs a mandal command as the panel does (a fresh CLI, no terminal) and
# prints {command, seconds, exit_code}.
bench_command() {
    local start code=0
    start=$EPOCHREALTIME
    "$MANDAL_ROOT/bin/mandal" "$@" > /dev/null 2>&1 < /dev/null || code=$?
    jq -n --arg command "$*" --argjson seconds "$(bench_since "$start")" --argjson exit_code "$code" \
        '{command: $command, seconds: $seconds, exit_code: $exit_code}'
}

# Every read command a panel page runs here, per app where a page asks per
# app: [{command, seconds, exit_code}], slowest first.
bench_commands() {
    local file name rows=()
    rows+=("$(bench_command version --json)")
    rows+=("$(bench_command inventory)")
    rows+=("$(bench_command updates:status)")
    rows+=("$(bench_command provision --check)")
    rows+=("$(bench_command firewall:list)")
    rows+=("$(bench_command php:list)")
    rows+=("$(bench_command php:available)")
    rows+=("$(bench_command db:list)")
    rows+=("$(bench_command backup:list)")
    rows+=("$(bench_command logs:sources)")
    for file in "$MANDAL_APPS_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        name="$(basename "$file" .conf)"
        [[ "$name" =~ $MANDAL_NAME_PATTERN ]] || continue
        rows+=("$(bench_command app:backup-list --name="$name")")
        if [[ "$(conf_get "$file" TYPE 2> /dev/null || true)" != "docker" ]]; then
            rows+=("$(bench_command app:releases --name="$name")")
            rows+=("$(bench_command worker:list --app="$name")")
        fi
    done
    printf '%s\n' "${rows[@]}" | jq -s 'sort_by(-.seconds)'
}

cmd_benchmark() {
    [[ $# -eq 0 ]] || die 64 "unknown option '$1'"
    require_root
    load_all_drivers
    local load="" process_start collectors commands
    [[ -r /proc/loadavg ]] && read -r load _ < /proc/loadavg
    process_start="$(bench_process_start)"
    collectors="$(bench_collectors)"
    commands="$(bench_commands)"
    jq -n \
        --arg version "$(mandal_version)" \
        --arg build "$(mandal_build_hash)" \
        --argjson vcpus "$(nproc 2> /dev/null || echo 0)" \
        --arg load "$load" \
        --argjson process_start "$process_start" \
        --argjson collectors "$collectors" \
        --argjson commands "$commands" \
        '{version: $version, build: $build, vcpus: $vcpus, load: (if $load == "" then null else ($load | tonumber) end), process_start: $process_start, collectors: $collectors, commands: $commands}'
}
