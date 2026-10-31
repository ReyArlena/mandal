#!/usr/bin/env bash
#
# Backups: compressed database dumps under /var/backups/mandal/db and app
# archives under /var/backups/mandal/apps, each with a local and a remote
# destination that have their own schedule and retention. Remotes are named
# S3-compatible stores described in /etc/mandal/remotes/<slug>.conf, with
# the credentials in an rclone config of their own under /etc/mandal/rclone.d.
#
# A backup target is "databases" (every database plus the panel's SQLite
# copy) or "app:<name>"; each has a key=value conf under /etc/mandal/backups.

MANDAL_BACKUPS_CONF_DIR="${MANDAL_BACKUPS_CONF_DIR:-${MANDAL_ETC}/backups}"
MANDAL_REMOTES_DIR="${MANDAL_REMOTES_DIR:-${MANDAL_ETC}/remotes}"
MANDAL_RCLONE_DIR="${MANDAL_RCLONE_DIR:-${MANDAL_ETC}/rclone.d}"
MANDAL_BACKUP_DIR="${MANDAL_BACKUP_DIR:-/var/backups/mandal/db}"
MANDAL_APP_BACKUP_DIR="${MANDAL_APP_BACKUP_DIR:-/var/backups/mandal/apps}"
MANDAL_BACKUP_LOG="${MANDAL_BACKUP_LOG:-${MANDAL_LOG_DIR}/backups.log}"
MANDAL_BACKUP_CRON="${MANDAL_BACKUP_CRON:-/etc/cron.d/mandal-backups}"
MANDAL_BACKUP_LOCK="${MANDAL_BACKUP_LOCK:-/run/lock/mandal-backup.lock}"
MANDAL_BACKUP_REMOTE_PATTERN='^[a-z][a-z0-9-]{1,31}$'
MANDAL_BACKUP_DEFAULT_SCHEDULE='30 2 * * *'
# Refuse an app archive when the filesystem would have less than this left
# after a backup the size of the previous one.
# shellcheck disable=SC2034  # consumed by commands/app-backup.sh
MANDAL_BACKUP_FREE_MARGIN=$((256 * 1024 * 1024))

# ---------------------------------------------------------------------------
# Targets
# ---------------------------------------------------------------------------

# backup_target_file databases|app:<name>
backup_target_file() {
    case "$1" in
        databases) printf '%s/databases.conf\n' "$MANDAL_BACKUPS_CONF_DIR" ;;
        app:*) printf '%s/app-%s.conf\n' "$MANDAL_BACKUPS_CONF_DIR" "${1#app:}" ;;
        *) die "unknown backup target '$1'" ;;
    esac
}

app_backup_exclude_file() {
    printf '%s/app-%s.exclude\n' "$MANDAL_BACKUPS_CONF_DIR" "$1"
}

backup_target_configured() {
    [[ -f "$(backup_target_file "$1")" ]]
}

backup_configured() {
    backup_target_configured databases
}

# backup_target_get target KEY (empty when unset)
backup_target_get() {
    conf_get "$(backup_target_file "$1")" "$2" 2> /dev/null || true
}

# Every target that has a conf, one per line.
backup_targets() {
    local file name
    [[ -f "$MANDAL_BACKUPS_CONF_DIR/databases.conf" ]] && echo databases
    for file in "$MANDAL_BACKUPS_CONF_DIR"/app-*.conf; do
        [[ -f "$file" ]] || continue
        name="$(basename "$file" .conf)"
        printf 'app:%s\n' "${name#app-}"
    done
}

# Apps with a backup conf, one name per line.
backup_apps() {
    backup_targets | { grep '^app:' || true; } | sed 's/^app://'
}

# Load a target's conf into the BACKUP_* variables, falling back to the
# defaults for anything missing so callers never see an empty value.
backup_target_load() {
    local target="$1"
    BACKUP_ENABLED="$(backup_target_get "$target" ENABLED)"
    BACKUP_LOCAL="$(backup_target_get "$target" LOCAL)"
    BACKUP_LOCAL_SCHEDULE="$(backup_target_get "$target" LOCAL_SCHEDULE)"
    BACKUP_LOCAL_KEEP="$(backup_target_get "$target" LOCAL_KEEP)"
    BACKUP_REMOTE="$(backup_target_get "$target" REMOTE)"
    BACKUP_REMOTE_SCHEDULE="$(backup_target_get "$target" REMOTE_SCHEDULE)"
    BACKUP_REMOTE_KEEP="$(backup_target_get "$target" REMOTE_KEEP)"
    BACKUP_REMOTE_PATH="$(backup_target_get "$target" REMOTE_PATH)"
    BACKUP_PAUSE="$(backup_target_get "$target" PAUSE)"
    backup_target_defaults
}

# Load the target keys from an uploaded file (the panel's form) instead.
backup_target_read() {
    local from="$1"
    BACKUP_ENABLED="$(conf_get "$from" ENABLED 2> /dev/null || true)"
    BACKUP_LOCAL="$(conf_get "$from" LOCAL 2> /dev/null || true)"
    BACKUP_LOCAL_SCHEDULE="$(conf_get "$from" LOCAL_SCHEDULE 2> /dev/null || true)"
    BACKUP_LOCAL_KEEP="$(conf_get "$from" LOCAL_KEEP 2> /dev/null || true)"
    BACKUP_REMOTE="$(conf_get "$from" REMOTE 2> /dev/null || true)"
    BACKUP_REMOTE_SCHEDULE="$(conf_get "$from" REMOTE_SCHEDULE 2> /dev/null || true)"
    BACKUP_REMOTE_KEEP="$(conf_get "$from" REMOTE_KEEP 2> /dev/null || true)"
    BACKUP_REMOTE_PATH="$(conf_get "$from" REMOTE_PATH 2> /dev/null || true)"
    BACKUP_PAUSE="$(conf_get "$from" PAUSE 2> /dev/null || true)"
    backup_target_defaults
}

backup_target_defaults() {
    BACKUP_ENABLED="${BACKUP_ENABLED:-true}"
    BACKUP_LOCAL="${BACKUP_LOCAL:-true}"
    BACKUP_LOCAL_SCHEDULE="${BACKUP_LOCAL_SCHEDULE:-$MANDAL_BACKUP_DEFAULT_SCHEDULE}"
    BACKUP_LOCAL_KEEP="${BACKUP_LOCAL_KEEP:-7}"
    BACKUP_REMOTE="${BACKUP_REMOTE:-}"
    BACKUP_REMOTE_SCHEDULE="${BACKUP_REMOTE_SCHEDULE:-$MANDAL_BACKUP_DEFAULT_SCHEDULE}"
    BACKUP_REMOTE_KEEP="${BACKUP_REMOTE_KEEP:-30}"
    BACKUP_REMOTE_PATH="${BACKUP_REMOTE_PATH:-}"
    BACKUP_PAUSE="${BACKUP_PAUSE:-false}"
}

validate_schedule() {
    [[ "$1" =~ ^([0-9*/,-]+[[:space:]]+){4}[0-9*/,A-Za-z-]+$ ]] || die 2 "invalid cron schedule '$1'"
}

validate_db_name() {
    [[ "$1" =~ ^[a-z0-9_]{1,64}$ || "$1" =~ ^[a-z][a-z0-9-]{1,31}-sqlite$ ]] || die 2 "invalid database name '$1'"
}

validate_remote_name() {
    [[ "$1" =~ $MANDAL_BACKUP_REMOTE_PATTERN ]] || die 2 "invalid remote name '$1': use 2-32 lower-case letters, digits or hyphens, starting with a letter"
}

# Check the BACKUP_* variables; the remote, when set, must exist.
backup_target_validate() {
    [[ "$BACKUP_ENABLED" =~ ^(true|false)$ ]] || die 2 "ENABLED must be true or false"
    [[ "$BACKUP_LOCAL" =~ ^(true|false)$ ]] || die 2 "LOCAL must be true or false"
    [[ "$BACKUP_PAUSE" =~ ^(true|false)$ ]] || die 2 "PAUSE must be true or false"
    validate_schedule "$BACKUP_LOCAL_SCHEDULE"
    validate_schedule "$BACKUP_REMOTE_SCHEDULE"
    [[ "$BACKUP_LOCAL_KEEP" =~ ^[0-9]{1,3}$ ]] || die 2 "LOCAL_KEEP must be a number between 0 and 999"
    [[ "$BACKUP_REMOTE_KEEP" =~ ^[0-9]{1,3}$ ]] || die 2 "REMOTE_KEEP must be a number between 0 and 999"
    [[ -z "$BACKUP_REMOTE_PATH" || "$BACKUP_REMOTE_PATH" =~ ^[A-Za-z0-9._-][A-Za-z0-9._/-]{0,199}$ ]] || die 2 "invalid REMOTE_PATH '$BACKUP_REMOTE_PATH' (letters, digits, . _ - and /, no leading slash)"
    if [[ -n "$BACKUP_REMOTE" ]]; then
        validate_remote_name "$BACKUP_REMOTE"
        remote_exists "$BACKUP_REMOTE" || die 2 "remote '$BACKUP_REMOTE' does not exist; add it with backup:remote first"
    fi
}

backup_render_target_conf() {
    cat <<EOF
# managed-by: mandal
ENABLED=$BACKUP_ENABLED
LOCAL=$BACKUP_LOCAL
LOCAL_SCHEDULE=$BACKUP_LOCAL_SCHEDULE
LOCAL_KEEP=$BACKUP_LOCAL_KEEP
REMOTE=$BACKUP_REMOTE
REMOTE_SCHEDULE=$BACKUP_REMOTE_SCHEDULE
REMOTE_KEEP=$BACKUP_REMOTE_KEEP
REMOTE_PATH=$BACKUP_REMOTE_PATH
EOF
    [[ "$1" == app:* ]] && printf 'PAUSE=%s\n' "$BACKUP_PAUSE"
    return 0
}

# backup_write_target target - write the conf from the BACKUP_* variables.
backup_write_target() {
    write_file "$(backup_target_file "$1")" 0644 < <(backup_render_target_conf "$1")
}

# Database backups on by default: written once, never over a user's choice.
backup_configure_defaults() {
    local file
    file="$(backup_target_file databases)"
    if [[ -f "$file" ]]; then
        printf '+ %s already exists, leaving it alone\n' "$file"
        backup_write_cron
    else
        BACKUP_ENABLED=true BACKUP_LOCAL=true BACKUP_LOCAL_SCHEDULE="" BACKUP_LOCAL_KEEP="" BACKUP_REMOTE="" BACKUP_REMOTE_SCHEDULE="" BACKUP_REMOTE_KEEP="" BACKUP_REMOTE_PATH="" BACKUP_PAUSE=""
        backup_target_defaults
        backup_write_target databases
        backup_write_cron databases
    fi
    run_cmd install -d -m 0700 "$MANDAL_BACKUP_DIR"
}

# JSON for a target's configuration, in the shape backup:status uses.
# shellcheck disable=SC2016  # jq filters use $vars on purpose
backup_target_json() {
    local target="$1" remote='null' exclude='[]'
    backup_target_load "$target"
    if [[ -n "$BACKUP_REMOTE" ]]; then
        remote="$(jq -n \
            --arg name "$BACKUP_REMOTE" \
            --arg schedule "$BACKUP_REMOTE_SCHEDULE" \
            --argjson keep "$BACKUP_REMOTE_KEEP" \
            --arg path "$BACKUP_REMOTE_PATH" \
            --arg bucket "$(remote_get "$BACKUP_REMOTE" BUCKET)" \
            --arg prefix "$(remote_get "$BACKUP_REMOTE" PATH)" \
            --arg endpoint "$(remote_get "$BACKUP_REMOTE" ENDPOINT)" \
            '{name: $name, schedule: $schedule, keep: $keep, path: $path, bucket: $bucket, prefix: $prefix, endpoint: $endpoint}')"
    fi
    if [[ "$target" == app:* && -r "$(app_backup_exclude_file "${target#app:}")" ]]; then
        exclude="$(json_lines < "$(app_backup_exclude_file "${target#app:}")")"
    fi
    jq -n \
        --argjson enabled "$BACKUP_ENABLED" \
        --argjson local_enabled "$BACKUP_LOCAL" \
        --arg schedule "$BACKUP_LOCAL_SCHEDULE" \
        --argjson keep "$BACKUP_LOCAL_KEEP" \
        --argjson remote "$remote" \
        --argjson pause "$BACKUP_PAUSE" \
        --argjson exclude "$exclude" \
        --argjson app "$([[ "$target" == app:* ]] && echo true || echo false)" \
        '{enabled: $enabled, local: {enabled: $local_enabled, schedule: $schedule, keep: $keep}, remote: $remote}
         + (if $app then {pause: $pause, exclude: $exclude} else {} end)'
}

# ---------------------------------------------------------------------------
# Remotes
# ---------------------------------------------------------------------------

remote_conf_file() {
    printf '%s/%s.conf\n' "$MANDAL_REMOTES_DIR" "$1"
}

rclone_conf_file() {
    printf '%s/%s.conf\n' "$MANDAL_RCLONE_DIR" "$1"
}

remote_exists() {
    [[ -f "$(remote_conf_file "$1")" ]]
}

# A remote the runs can use: both halves must be in place.
remote_usable() {
    [[ -f "$(remote_conf_file "$1")" && -f "$(rclone_conf_file "$1")" ]]
}

remote_get() {
    conf_get "$(remote_conf_file "$1")" "$2" 2> /dev/null || true
}

remotes() {
    local file
    for file in "$MANDAL_REMOTES_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        basename "$file" .conf
    done
}

# Targets whose REMOTE is this slug, one per line.
remote_used_by() {
    local slug="$1" target
    for target in $(backup_targets); do
        [[ "$(backup_target_get "$target" REMOTE)" == "$slug" ]] && echo "$target"
    done
    return 0
}

# rclone_cmd slug args... - rclone against that remote's own config only.
rclone_cmd() {
    local slug="$1"
    shift
    rclone --config "$(rclone_conf_file "$slug")" --quiet "$@"
}

# remote_target slug subfolder leaf -> slug:bucket/prefix/subfolder/leaf,
# dropping empty segments.
remote_target() {
    local slug="$1" path segment
    path="$(remote_get "$slug" BUCKET)"
    for segment in "$(remote_get "$slug" PATH)" "$2" "$3"; do
        segment="${segment#/}"
        segment="${segment%/}"
        [[ -n "$segment" ]] && path="$path/$segment"
    done
    printf '%s:%s\n' "$slug" "$path"
}

# backup_render_remote_conf label endpoint region bucket path
backup_render_remote_conf() {
    cat <<EOF
# managed-by: mandal
LABEL=$1
ENDPOINT=$2
REGION=$3
BUCKET=$4
PATH=$5
EOF
}

# backup_render_rclone slug endpoint region key secret
backup_render_rclone() {
    cat <<EOF
# managed-by: mandal
[$1]
type = s3
provider = Other
access_key_id = $4
secret_access_key = $5
endpoint = $2
region = $3
acl = private
EOF
}

# shellcheck disable=SC2016
remote_list_json() {
    local slug
    for slug in $(remotes); do
        jq -n \
            --arg name "$slug" \
            --arg label "$(remote_get "$slug" LABEL)" \
            --arg endpoint "$(remote_get "$slug" ENDPOINT)" \
            --arg region "$(remote_get "$slug" REGION)" \
            --arg bucket "$(remote_get "$slug" BUCKET)" \
            --arg path "$(remote_get "$slug" PATH)" \
            --argjson used_by "$(remote_used_by "$slug" | json_lines)" \
            '{name: $name, label: $label, endpoint: $endpoint, region: $region, bucket: $bucket, path: $path, used_by: $used_by}'
    done | jq -s '.'
}

# ---------------------------------------------------------------------------
# Cron
# ---------------------------------------------------------------------------

backup_cron_line() {
    printf '%s root /opt/mandal/bin/mandal %s --destination=%s >> %s 2>&1\n' "$1" "$2" "$3" "$MANDAL_BACKUP_LOG"
}

# backup_render_cron [with] [without] - one line per enabled destination of
# every enabled target; a target whose two destinations share a schedule
# gets a single run with --destination=all. "with" names the target whose
# settings are the BACKUP_* variables rather than its file (so a dry run
# shows the cron file it would write), "without" one to leave out.
backup_render_cron() {
    local with="${1:-}" without="${2:-}" target command targets
    local with_vars=()
    [[ -n "$with" ]] && with_vars=("$BACKUP_ENABLED" "$BACKUP_LOCAL" "$BACKUP_LOCAL_SCHEDULE" "$BACKUP_REMOTE" "$BACKUP_REMOTE_SCHEDULE")
    targets="$(backup_targets)"
    if [[ -n "$with" ]] && ! grep -qx "$with" <<< "$targets"; then
        targets="${targets:+$targets$'\n'}$with"
    fi
    printf '# managed-by: mandal\nSHELL=/bin/bash\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
    for target in $targets; do
        [[ "$target" != "$without" ]] || continue
        if [[ "$target" == "$with" ]]; then
            BACKUP_ENABLED="${with_vars[0]}" BACKUP_LOCAL="${with_vars[1]}" BACKUP_LOCAL_SCHEDULE="${with_vars[2]}" BACKUP_REMOTE="${with_vars[3]}" BACKUP_REMOTE_SCHEDULE="${with_vars[4]}"
        else
            backup_target_load "$target"
        fi
        [[ "$BACKUP_ENABLED" == true ]] || continue
        case "$target" in
            databases) command="backup:run" ;;
            *) command="app:backup --name=${target#app:}" ;;
        esac
        if [[ "$BACKUP_LOCAL" == true && -n "$BACKUP_REMOTE" && "$BACKUP_LOCAL_SCHEDULE" == "$BACKUP_REMOTE_SCHEDULE" ]]; then
            backup_cron_line "$BACKUP_LOCAL_SCHEDULE" "$command" all
            continue
        fi
        [[ "$BACKUP_LOCAL" == true ]] && backup_cron_line "$BACKUP_LOCAL_SCHEDULE" "$command" local
        [[ -n "$BACKUP_REMOTE" ]] && backup_cron_line "$BACKUP_REMOTE_SCHEDULE" "$command" remote
    done
    return 0
}

# backup_write_cron [with] [without] - see backup_render_cron. Removed
# when no line is left, so cron never runs a disabled backup.
backup_write_cron() {
    local content
    content="$(backup_render_cron "$@")"
    if [[ "$(wc -l <<< "$content")" -le 3 ]]; then
        [[ -f "$MANDAL_BACKUP_CRON" ]] && run_cmd rm -f "$MANDAL_BACKUP_CRON"
        return 0
    fi
    write_file "$MANDAL_BACKUP_CRON" 0644 <<< "$content"
}

# ---------------------------------------------------------------------------
# Runs: shared destination logic
# ---------------------------------------------------------------------------

backup_log() {
    printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

# The lock shared by backup:run and app:backup; a second run fails at once
# instead of queueing behind a long archive.
backup_take_lock() {
    mkdir -p "$(dirname "$MANDAL_BACKUP_LOCK")" "$MANDAL_LOG_DIR"
    exec 8> "$MANDAL_BACKUP_LOCK"
    flock -n 8 || die 75 "a backup is already running"
}

# backup_resolve target local|remote|all - load the conf and decide which
# destinations this run serves (BACKUP_DO_LOCAL / BACKUP_DO_REMOTE). "Back
# up now" ignores ENABLED on purpose: only the cron lines depend on it.
backup_resolve() {
    local target="$1" destination="$2"
    [[ "$destination" =~ ^(local|remote|all)$ ]] || die 2 "--destination must be local, remote or all"
    backup_target_load "$target"
    BACKUP_DO_LOCAL=false
    BACKUP_DO_REMOTE=false
    case "$destination" in
        local) BACKUP_DO_LOCAL=true ;;
        remote) [[ -n "$BACKUP_REMOTE" ]] || die "no remote destination is set for $target"; BACKUP_DO_REMOTE=true ;;
        all)
            [[ "$BACKUP_LOCAL" == true ]] && BACKUP_DO_LOCAL=true
            [[ -n "$BACKUP_REMOTE" ]] && BACKUP_DO_REMOTE=true
            [[ "$BACKUP_DO_LOCAL" == true || "$BACKUP_DO_REMOTE" == true ]] || die "no destination is enabled for $target (turn on local or choose a remote)"
            ;;
    esac
}

# Where a new file goes: the kept directory, or a scratch directory on the
# same filesystem when only the remote wants it.
backup_target_dir() {
    local base="$1" leaf="$2"
    if [[ "$BACKUP_DO_LOCAL" == true ]]; then
        printf '%s/%s\n' "$base" "$leaf"
    else
        printf '%s/.tmp/%s\n' "$base" "$leaf"
    fi
}

# backup_prune_local dir pattern keep - keep the newest N files matching
# the glob (names carry the timestamp, so sorting by name is enough).
backup_prune_local() {
    local dir="$1" pattern="$2" keep="$3" files count
    [[ -d "$dir" ]] || return 0
    mapfile -t files < <(find "$dir" -maxdepth 1 -type f -name "$pattern" -printf '%f\n' | sort)
    count=${#files[@]}
    while [[ $count -gt $keep ]]; do
        run_cmd rm -f "$dir/${files[0]}"
        files=("${files[@]:1}")
        count=$((count - 1))
    done
}

# backup_prune_remote slug target regex keep
backup_prune_remote() {
    local slug="$1" target="$2" regex="$3" keep="$4" files count
    dry_run && {
        printf '+ prune remote %s (keep %s)\n' "$target" "$keep"
        return 0
    }
    mapfile -t files < <(rclone_cmd "$slug" lsf "$target" 2> /dev/null | grep -E "$regex" | sort)
    count=${#files[@]}
    while [[ $count -gt $keep ]]; do
        run_cmd rclone_cmd "$slug" deletefile "$target/${files[0]}" || return 1
        files=("${files[@]:1}")
        count=$((count - 1))
    done
}

# backup_store base kind leaf file regex label - after the file exists: upload
# and prune the remote when this run serves it, then keep or drop the local
# copy. A broken remote never loses the backup: the file stays local and
# the failure is logged as "failed-remote <label> <reason>" so the run can
# report it. Returns 1 when the remote part failed.
backup_store() {
    local base="$1" kind="$2" leaf="$3" file="$4" regex="$5" label="${6:-$3}" status=0 target
    if [[ "$BACKUP_DO_REMOTE" == true ]]; then
        if ! remote_usable "$BACKUP_REMOTE"; then
            backup_log "failed-remote $label remote $BACKUP_REMOTE is missing its configuration"
            status=1
        else
            target="$(remote_target "$BACKUP_REMOTE" "$BACKUP_REMOTE_PATH" "$kind/$leaf")"
            if run_cmd rclone_cmd "$BACKUP_REMOTE" copy "$file" "$target/"; then
                backup_prune_remote "$BACKUP_REMOTE" "$target" "$regex" "$BACKUP_REMOTE_KEEP" || backup_log "failed-remote $label pruning $target failed"
            else
                backup_log "failed-remote $label upload to $target failed"
                status=1
            fi
        fi
    fi
    if [[ "$BACKUP_DO_LOCAL" == true ]]; then
        backup_prune_local "$base/$leaf" "${leaf}-*" "$BACKUP_LOCAL_KEEP"
    elif [[ "$status" -eq 0 ]]; then
        run_cmd rm -f "$file"
    else
        printf '+ keeping %s locally because the remote failed\n' "$file"
    fi
    return "$status"
}

# backup_prepare_dir dir - create a 0700 directory for new backup files.
backup_prepare_dir() {
    dry_run && return 0
    mkdir -p "$1"
    chmod 0700 "$1"
}

# The newest timestamped log line for a target. App lines read
# "<status> app <name> ...", database lines "<status> <database> ...",
# where status is ok, failed or failed-remote.
backup_last_run_line() {
    local target="$1"
    [[ -r "$MANDAL_BACKUP_LOG" ]] || return 0
    local pattern='^[0-9T:-]+Z (ok|failed|failed-remote) '
    if [[ "$target" == databases ]]; then
        { grep -E "$pattern" "$MANDAL_BACKUP_LOG" || true; } | { grep -vE "${pattern}app " || true; } | tail -n 1
    else
        { grep -E "${pattern}app ${target#app:}( |$)" "$MANDAL_BACKUP_LOG" || true; } | tail -n 1
    fi
}

# shellcheck disable=SC2016
backup_last_run_json() {
    local line
    line="$(backup_last_run_line "$1")"
    jq -n --arg last "$line" 'if $last == "" then null else ($last | capture("^(?<at>\\S+) (?<status>\\S+) ?(?<rest>.*)$") // {at: null, status: $last, rest: ""}) end'
}

# ---------------------------------------------------------------------------
# Databases
# ---------------------------------------------------------------------------

# The databases to back up: everything that is not a system schema or a
# database produced by backup:restore (those are copies for checking).
backup_databases() {
    local list
    list="$(db_list | jq -r '.databases[]')"
    printf '%s\n' "$list" | { grep -vE '^$|_restore_[0-9]{14}$' || true; } | sort -u
}

backup_file_name() {
    printf '%s-%s.sql.zst\n' "$1" "$(date -u +%Y%m%d%H%M%S)"
}

# backup_dump db file
backup_dump() {
    local db="$1" file="$2"
    printf '+ dump %s -> %s\n' "$db" "$file"
    dry_run && return 0
    backup_prepare_dir "$(dirname "$file")"
    if ! mariadb-dump --single-transaction --quick --routines --triggers --events "$db" | zstd -q -T0 -o "$file"; then
        rm -f "$file"
        return 1
    fi
    chmod 0600 "$file"
}

# Copy a SQLite file (the panel's own database) into the backup set under
# the name <app>-sqlite, compressed like the dumps.
backup_sqlite() {
    local source="$1" file="$2"
    printf '+ copy %s -> %s\n' "$source" "$file"
    dry_run && return 0
    [[ -f "$source" ]] || return 1
    backup_prepare_dir "$(dirname "$file")"
    if has_cmd sqlite3; then
        sqlite3 "$source" ".backup '${file%.zst}.tmp'" || { rm -f "${file%.zst}.tmp"; return 1; }
    else
        cp "$source" "${file%.zst}.tmp" || return 1
    fi
    zstd -q -T0 --rm "${file%.zst}.tmp" -o "$file" || { rm -f "${file%.zst}.tmp" "$file"; return 1; }
    chmod 0600 "$file"
}

# backup_run [db] [destination]
backup_run() {
    local only="${1:-}" destination="${2:-all}" db file failed=0 remote_failed=0 count=0 app source
    backup_configured || die "backups are not configured; run mandal backup:configure first"
    backup_resolve databases "$destination"
    for app in $(panel_apps); do
        [[ -z "$only" || "$only" == "${app}-sqlite" ]] || continue
        count=$((count + 1))
        source="$(app_home "$app")/shared/database.sqlite"
        file="$(backup_target_dir "$MANDAL_BACKUP_DIR" "${app}-sqlite")/$(backup_file_name "${app}-sqlite")"
        if ! backup_sqlite "$source" "$file"; then
            backup_log "failed ${app}-sqlite"
            failed=$((failed + 1))
            continue
        fi
        backup_log "ok ${app}-sqlite $file $(dry_run || stat -c %s "$file" 2> /dev/null || echo 0)"
        backup_store "$MANDAL_BACKUP_DIR" db "${app}-sqlite" "$file" "^${app}-sqlite-[0-9]{14}\.sql\.zst$" || remote_failed=$((remote_failed + 1))
    done
    for db in $(backup_databases); do
        [[ -z "$only" || "$db" == "$only" ]] || continue
        count=$((count + 1))
        file="$(backup_target_dir "$MANDAL_BACKUP_DIR" "$db")/$(backup_file_name "$db")"
        if ! backup_dump "$db" "$file"; then
            backup_log "failed $db"
            failed=$((failed + 1))
            continue
        fi
        backup_log "ok $db $file $(dry_run || stat -c %s "$file" 2> /dev/null || echo 0)"
        backup_store "$MANDAL_BACKUP_DIR" db "$db" "$file" "^${db}-[0-9]{14}\.sql\.zst$" || remote_failed=$((remote_failed + 1))
    done
    [[ "$count" -gt 0 ]] || die "no databases to back up${only:+ (no database named $only)}"
    [[ "$failed" -eq 0 ]] || die "$failed backup(s) failed"
    [[ "$remote_failed" -eq 0 ]] || die "$remote_failed backup(s) could not reach the remote '$BACKUP_REMOTE'; the local copies were kept"
}

# JSON: local and remote backups per database (and the panel's sqlite copy).
backup_list_json() {
    local only="${1:-}" db local_files remote_files
    backup_target_load databases
    for db in $(backup_databases) $(for app in $(panel_apps); do printf '%s-sqlite\n' "$app"; done); do
        [[ -z "$only" || "$db" == "$only" ]] || continue
        # A database that has never been backed up has no directory yet.
        local_files="$({ find "$MANDAL_BACKUP_DIR/$db" -maxdepth 1 -type f -name "${db}-*.sql.zst" -printf '%f\t%s\t%TY-%Tm-%TdT%TH:%TM:%TSZ\n' 2> /dev/null || true; } | sort | json_tsv file size modified | jq 'map(.size |= tonumber)')"
        remote_files='[]'
        if [[ -n "$BACKUP_REMOTE" ]] && remote_usable "$BACKUP_REMOTE" && ! dry_run; then
            remote_files="$({ rclone_cmd "$BACKUP_REMOTE" lsjson "$(remote_target "$BACKUP_REMOTE" "$BACKUP_REMOTE_PATH" "db/$db")" 2> /dev/null || echo '[]'; } | jq 'map({file: .Name, size: .Size, modified: .ModTime})')"
        fi
        jq -n --arg db "$db" --argjson local "$local_files" --argjson remote "$remote_files" '{database: $db, local: $local, remote: $remote}'
    done | jq -s '.'
}

# shellcheck disable=SC2016
backup_apps_status_json() {
    local app
    for app in $(backup_apps); do
        jq -n \
            --arg name "$app" \
            --argjson enabled "$(backup_target_get "app:$app" ENABLED | grep -qx true && echo true || echo false)" \
            --argjson last_run "$(backup_last_run_json "app:$app")" \
            '{name: $name, configured: true, enabled: $enabled, last_run: $last_run}'
    done | jq -s '.'
}

# shellcheck disable=SC2016
backup_status_json() {
    local config='{"enabled": false, "local": null, "remote": null}'
    backup_configured && config="$(backup_target_json databases)"
    jq -n \
        --argjson configured "$(backup_configured && echo true || echo false)" \
        --argjson config "$config" \
        --argjson last_run "$(backup_last_run_json databases)" \
        --argjson databases "$(backup_databases | json_lines)" \
        --argjson apps "$(backup_apps_status_json)" \
        '{configured: $configured} + $config + {last_run: $last_run, databases: $databases, apps: $apps}'
}

# backup_delete db file local|remote
backup_delete() {
    local db="$1" name="$2" where="$3"
    validate_db_name "$db"
    [[ "$name" =~ ^[a-z0-9_-]+-[0-9]{14}\.sql\.zst$ && "$name" == "${db}-"* ]] || die 2 "invalid backup file name '$name'"
    if [[ "$where" == local ]]; then
        [[ -f "$MANDAL_BACKUP_DIR/$db/$name" ]] || dry_run || die "backup '$name' not found locally"
        run_cmd rm -f "$MANDAL_BACKUP_DIR/$db/$name"
    else
        backup_target_load databases
        [[ -n "$BACKUP_REMOTE" ]] || die "no remote is set for database backups"
        run_cmd rclone_cmd "$BACKUP_REMOTE" deletefile "$(remote_target "$BACKUP_REMOTE" "$BACKUP_REMOTE_PATH" "db/$db")/$name"
    fi
    printf '\nDeleted %s (%s).\n' "$name" "$where"
}

# backup_restore db file [into]
backup_restore() {
    local db="$1" name="$2" into="${3:-}" file tmp=""
    validate_db_name "$db"
    [[ "$name" =~ ^[a-z0-9_]+-[0-9]{14}\.sql\.zst$ ]] || die 2 "invalid backup file name '$name'"
    [[ -n "$into" ]] || into="${db}_restore_$(date -u +%Y%m%d%H%M%S)"
    validate_db_name "$into"
    file="$MANDAL_BACKUP_DIR/$db/$name"
    if [[ ! -f "$file" ]]; then
        backup_target_load databases
        [[ -n "$BACKUP_REMOTE" ]] || dry_run || die "backup '$name' not found locally and no remote is configured"
        tmp="$(mktemp -d)"
        run_cmd rclone_cmd "$BACKUP_REMOTE" copy "$(remote_target "$BACKUP_REMOTE" "$BACKUP_REMOTE_PATH" "db/$db")/$name" "$tmp/"
        file="$tmp/$name"
    fi
    step "Restoring $name into database $into"
    db_sql "CREATE DATABASE IF NOT EXISTS \`${into}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    printf '+ zstd -dc %s | mariadb %s\n' "$file" "$into"
    if ! dry_run; then
        zstd -dc "$file" | mariadb "$into"
    fi
    if [[ "$into" != "$db" ]]; then
        db_sql "GRANT ALL PRIVILEGES ON \`${into}\`.* TO '${db}'@'localhost'"
        db_sql "FLUSH PRIVILEGES"
    fi
    [[ -n "$tmp" ]] && ! dry_run && rm -rf "$tmp"
    printf '\nRestored %s into %s.\n' "$name" "$into"
}
