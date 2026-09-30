#!/usr/bin/env bash
#
# Logs: a fixed list of named sources (files and journald units) that the
# panel may read. Sources are addressed by id, never by path, so this can
# not be used to read arbitrary files through sudo.

MANDAL_NGINX_LOG_DIR="${MANDAL_NGINX_LOG_DIR:-/var/log/nginx}"
MANDAL_SUPERVISOR_LOG_DIR="${MANDAL_SUPERVISOR_LOG_DIR:-/var/log/supervisor}"
MANDAL_VAR_LOG="${MANDAL_VAR_LOG:-/var/log}"
MANDAL_LOGS_MAX_BYTES="${MANDAL_LOGS_MAX_BYTES:-524288}"
# shellcheck disable=SC2034  # used by commands/logs.sh
MANDAL_LOGS_MAX_LINES=5000

# Every source as TSV: id, label, kind, target, app, scope
# kind: file (target is a path), journal (target is a unit, or "kernel"), laravel (target is a directory)
logs_sources_tsv() {
    local unit dir version app name worker file

    for unit in nginx mariadb dragonfly supervisor docker fail2ban ssh cron unattended-upgrades; do
        unit_exists "$unit" || continue
        printf 'journal:%s\t%s (service)\tjournal\t%s\t\tserver\n' "$unit" "$unit" "$unit"
    done
    for dir in "$MANDAL_PHP_DIR"/*/; do
        [[ -d "$dir" ]] || continue
        version="$(basename "$dir")"
        unit_exists "php${version}-fpm" || continue
        printf 'journal:php%s-fpm\tPHP-FPM %s (service)\tjournal\tphp%s-fpm\t\tserver\n' "$version" "$version" "$version"
        [[ -f "$MANDAL_VAR_LOG/php${version}-fpm.log" ]] && printf 'php:%s:master\tPHP-FPM %s log\tfile\t%s\t\tserver\n' "$version" "$version" "$MANDAL_VAR_LOG/php${version}-fpm.log"
    done
    printf 'journal:kernel\tKernel and firewall blocks\tjournal\tkernel\t\tserver\n'
    printf 'journal:auth\tAuthentication (sshd, sudo)\tjournal\tauth\t\tserver\n'
    [[ -f "$MANDAL_NGINX_LOG_DIR/access.log" ]] && printf 'nginx:access\tnginx access (global)\tfile\t%s\t\tserver\n' "$MANDAL_NGINX_LOG_DIR/access.log"
    [[ -f "$MANDAL_NGINX_LOG_DIR/error.log" ]] && printf 'nginx:error\tnginx error (global)\tfile\t%s\t\tserver\n' "$MANDAL_NGINX_LOG_DIR/error.log"
    [[ -f "$MANDAL_VAR_LOG/fail2ban.log" ]] && printf 'fail2ban\tfail2ban\tfile\t%s\t\tserver\n' "$MANDAL_VAR_LOG/fail2ban.log"
    [[ -f "$MANDAL_VAR_LOG/apt/history.log" ]] && printf 'apt:history\tPackage changes\tfile\t%s\t\tserver\n' "$MANDAL_VAR_LOG/apt/history.log"
    [[ -f "$MANDAL_VAR_LOG/unattended-upgrades/unattended-upgrades.log" ]] && printf 'apt:unattended\tAutomatic updates\tfile\t%s\t\tserver\n' "$MANDAL_VAR_LOG/unattended-upgrades/unattended-upgrades.log"
    [[ -f "$MANDAL_LOG_DIR/actions.log" ]] && printf 'mandal:actions\tMandal actions\tfile\t%s\t\tserver\n' "$MANDAL_LOG_DIR/actions.log"
    [[ -f "$MANDAL_LOG_DIR/backups.log" ]] && printf 'mandal:backups\tBackups\tfile\t%s\t\tserver\n' "$MANDAL_LOG_DIR/backups.log"

    for file in "$MANDAL_APPS_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        app="$(basename "$file" .conf)"
        [[ "$app" =~ $MANDAL_NAME_PATTERN ]] || continue
        version="$(conf_get "$file" PHP 2> /dev/null || true)"
        printf 'nginx:%s:access\t%s nginx access\tfile\t%s\t%s\tapp\n' "$app" "$app" "$MANDAL_NGINX_LOG_DIR/${app}-access.log" "$app"
        printf 'nginx:%s:error\t%s nginx error\tfile\t%s\t%s\tapp\n' "$app" "$app" "$MANDAL_NGINX_LOG_DIR/${app}-error.log" "$app"
        printf 'laravel:%s\t%s Laravel log\tlaravel\t%s\t%s\tapp\n' "$app" "$app" "$(app_home "$app")/shared/storage/logs" "$app"
        [[ -n "$version" ]] && printf 'php:%s:slow\t%s PHP slow requests\tfile\t%s\t%s\tapp\n' "$app" "$app" "$MANDAL_VAR_LOG/php${version}-fpm-${app}-slow.log" "$app"
        for name in "$MANDAL_SUPERVISOR_DIR/${app}"-*.conf; do
            [[ -f "$name" ]] || continue
            worker="$(basename "$name" .conf)"
            worker="${worker#"${app}"-}"
            printf 'supervisor:%s:%s\t%s worker %s\tfile\t%s\t%s\tapp\n' "$app" "$worker" "$app" "$worker" "$MANDAL_SUPERVISOR_LOG_DIR/${app}-${worker}.log" "$app"
        done
        printf 'deploy:%s\t%s deployments\tdeploys\t%s\t%s\tapp\n' "$app" "$app" "${MANDAL_DEPLOY_LOG_DIR:-${MANDAL_LOG_DIR}/deploys}/${app}" "$app"
    done
}

logs_sources_json() {
    local app="${1:-}"
    logs_sources_tsv | json_tsv id label kind target app scope | jq --arg app "$app" '
        map(select($app == "" or .app == $app))
        | map(. + {exists: true})
    '
}

# Prints the TSV row for one source id, or fails.
logs_source() {
    local id="$1"
    [[ "$id" =~ ^[a-z0-9:.-]{1,80}$ ]] || die 2 "invalid source '$id'"
    logs_sources_tsv | awk -F'\t' -v id="$id" '$1 == id {print; found = 1} END {exit !found}' || die 2 "unknown log source '$id'"
}

# Escape a string for use in a jq JSON value.
logs_json_string() {
    jq -Rn --arg v "$1" '$v'
}

# logs_read_file path lines grep after
# Lines are returned newest last. "after" is a byte offset from a previous
# read; when given, only bytes written since then are returned.
logs_read_file() {
    local path="$1" lines="$2" pattern="$3" after="$4" size=0 content="" truncated=false
    if [[ ! -f "$path" ]]; then
        jq -n --arg path "$path" '{exists: false, lines: [], cursor: "0", truncated: false}'
        return 0
    fi
    size="$(stat -c %s "$path")"
    if [[ -n "$after" && "$after" =~ ^[0-9]+$ && "$after" -le "$size" ]]; then
        content="$(tail -c +"$((after + 1))" "$path" | head -c "$MANDAL_LOGS_MAX_BYTES")"
    else
        content="$(tail -n "$lines" "$path" | tail -c "$MANDAL_LOGS_MAX_BYTES")"
    fi
    if [[ -n "$pattern" ]]; then
        content="$(printf '%s\n' "$content" | { grep -i -F -- "$pattern" || true; } | tail -n "$lines")"
    fi
    [[ "$(printf '%s' "$content" | wc -c)" -ge "$MANDAL_LOGS_MAX_BYTES" ]] && truncated=true
    printf '%s\n' "$content" | jq -R -s --arg cursor "$size" --argjson truncated "$truncated" '
        {exists: true, lines: (split("\n") | map(select(length > 0))), cursor: $cursor, truncated: $truncated}
    '
}

# logs_read_journal unit lines grep since after
logs_read_journal() {
    local unit="$1" lines="$2" pattern="$3" since="$4" after="$5" args=() output cursor=""
    args=(--no-pager -o short-iso -n "$lines" --show-cursor)
    case "$unit" in
        kernel) args+=(-k) ;;
        auth) args+=(-t sshd -t sudo) ;;
        *) args+=(-u "$unit") ;;
    esac
    [[ -n "$since" ]] && args+=(--since "$since")
    [[ -n "$after" ]] && args+=(--after-cursor "$after")
    [[ -n "$pattern" ]] && args+=(--grep "$(printf '%s' "$pattern" | sed 's/[][\\.*^$+?(){}|]/\\&/g')" --case-sensitive=false)
    if ! has_cmd journalctl; then
        jq -n '{exists: false, lines: [], cursor: "", truncated: false}'
        return 0
    fi
    output="$(journalctl "${args[@]}" 2> /dev/null | head -c "$MANDAL_LOGS_MAX_BYTES" || true)"
    cursor="$(printf '%s\n' "$output" | { grep -oE '^-- cursor: .*$' || true; } | sed 's/^-- cursor: //' | tail -n 1)"
    printf '%s\n' "$output" | { grep -vE '^-- (cursor:|No entries)' || true; } | jq -R -s --arg cursor "$cursor" '
        {exists: true, lines: (split("\n") | map(select(length > 0))), cursor: $cursor, truncated: false}
    '
}

# Laravel logs: the newest *.log in the directory, split into entries at
# "[YYYY-MM-DD HH:MM:SS] env.LEVEL:" headers so stack traces stay attached.
logs_read_laravel() {
    local dir="$1" lines="$2" pattern="$3" level="$4" after="$5" file
    file="$(find "$dir" -maxdepth 1 -type f -name '*.log' -printf '%T@ %p\n' 2> /dev/null | sort -n | tail -n 1 | cut -d' ' -f2-)"
    if [[ -z "$file" ]]; then
        jq -n '{exists: false, file: null, entries: [], cursor: "0", truncated: false}'
        return 0
    fi
    local size content
    size="$(stat -c %s "$file")"
    if [[ -n "$after" && "$after" =~ ^[0-9]+$ && "$after" -le "$size" ]]; then
        content="$(tail -c +"$((after + 1))" "$file" | head -c "$MANDAL_LOGS_MAX_BYTES")"
    else
        content="$(tail -c "$MANDAL_LOGS_MAX_BYTES" "$file")"
    fi
    printf '%s\n' "$content" | awk '
        BEGIN { n = 0 }
        /^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9:]{8}[^]]*\] [A-Za-z_-]+\.[A-Z]+:/ { n++ }
        n > 0 { entries[n] = entries[n] $0 "\n" }
        END { for (i = 1; i <= n; i++) printf "%s\x1e", entries[i] }
    ' | jq -R -s --arg cursor "$size" --arg file "$file" --arg level "$level" --arg pattern "$pattern" --argjson limit "$lines" '
        split("\u001e")
        | map(select(length > 0))
        | map({
            at: (capture("^\\[(?<at>[^\\]]+)\\]") | .at),
            level: (capture("^\\[[^\\]]+\\] [A-Za-z_-]+\\.(?<level>[A-Z]+):") | .level),
            message: (split("\n")[0] | sub("^\\[[^\\]]+\\] [A-Za-z_-]+\\.[A-Z]+: "; "")),
            text: .
          })
        | map(select($level == "" or (.level | ascii_downcase) == ($level | ascii_downcase)))
        | map(select($pattern == "" or (.text | ascii_downcase | contains($pattern | ascii_downcase))))
        | {exists: true, file: $file, entries: .[-$limit:], cursor: $cursor, truncated: false}
    '
}

# Deployment logs: the newest file in the app's deploy log directory.
logs_read_deploys() {
    local dir="$1" lines="$2" pattern="$3" after="$4" file
    file="$(find "$dir" -maxdepth 1 -type f -name '*.log' 2> /dev/null | sort | tail -n 1)"
    if [[ -z "$file" ]]; then
        jq -n '{exists: false, lines: [], cursor: "0", truncated: false}'
        return 0
    fi
    logs_read_file "$file" "$lines" "$pattern" "$after" | jq --arg file "$file" '. + {file: $file}'
}

# logs_read id lines grep since level after
logs_read() {
    local id="$1" lines="$2" pattern="$3" since="$4" level="$5" after="$6" row kind target
    row="$(logs_source "$id")"
    kind="$(cut -f3 <<< "$row")"
    target="$(cut -f4 <<< "$row")"
    case "$kind" in
        file) logs_read_file "$target" "$lines" "$pattern" "$after" ;;
        journal) logs_read_journal "$target" "$lines" "$pattern" "$since" "$after" ;;
        laravel) logs_read_laravel "$target" "$lines" "$pattern" "$level" "$after" ;;
        deploys) logs_read_deploys "$target" "$lines" "$pattern" "$after" ;;
        *) die "unknown source kind '$kind'" ;;
    esac | jq --arg id "$id" --arg kind "$kind" '. + {source: $id, kind: $kind}'
}
