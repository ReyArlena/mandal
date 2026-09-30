#!/usr/bin/env bash
#
# Database backups: compressed dumps under /var/backups/mandal/db, optional
# upload to any S3-compatible store through rclone, separate local and
# remote retention, and restores into a fresh database by default.

MANDAL_BACKUP_CONF="${MANDAL_ETC}/backups.conf"
MANDAL_RCLONE_CONF="${MANDAL_ETC}/rclone.conf"
MANDAL_BACKUP_DIR="${MANDAL_BACKUP_DIR:-/var/backups/mandal/db}"
MANDAL_BACKUP_LOG="${MANDAL_LOG_DIR}/backups.log"
MANDAL_BACKUP_CRON="${MANDAL_BACKUP_CRON:-/etc/cron.d/mandal-backups}"
MANDAL_BACKUP_LOCK="${MANDAL_BACKUP_LOCK:-/run/lock/mandal-backup.lock}"
MANDAL_RCLONE_REMOTE="mandal-remote"

backup_conf_get() {
    conf_get "$MANDAL_BACKUP_CONF" "$1" 2> /dev/null || true
}

backup_configured() {
    [[ -f "$MANDAL_BACKUP_CONF" ]]
}

backup_remote_enabled() {
    [[ "$(backup_conf_get REMOTE)" == "true" ]]
}

backup_remote_target() {
    local bucket path
    bucket="$(backup_conf_get S3_BUCKET)"
    path="$(backup_conf_get S3_PATH)"
    printf '%s:%s%s\n' "$MANDAL_RCLONE_REMOTE" "$bucket" "${path:+/${path#/}}"
}

rclone_cmd() {
    rclone --config "$MANDAL_RCLONE_CONF" --quiet "$@"
}

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

validate_schedule() {
    [[ "$1" =~ ^([0-9*/,-]+[[:space:]]+){4}[0-9*/,A-Za-z-]+$ ]] || die 2 "invalid cron schedule '$1'"
}

validate_db_name() {
    [[ "$1" =~ ^[a-z0-9_]{1,64}$ ]] || die 2 "invalid database name '$1'"
}

# backup_render_conf schedule keep_local keep_remote remote bucket path endpoint region
backup_render_conf() {
    cat <<EOF
# managed-by: mandal
SCHEDULE=$1
KEEP_LOCAL=$2
KEEP_REMOTE=$3
REMOTE=$4
S3_BUCKET=$5
S3_PATH=$6
S3_ENDPOINT=$7
S3_REGION=$8
EOF
}

# backup_render_rclone endpoint region key secret
backup_render_rclone() {
    cat <<EOF
# managed-by: mandal
[${MANDAL_RCLONE_REMOTE}]
type = s3
provider = Other
access_key_id = $3
secret_access_key = $4
endpoint = $1
region = $2
acl = private
EOF
}

backup_render_cron() {
    printf '# managed-by: mandal\nSHELL=/bin/bash\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n%s root /opt/mandal/bin/mandal backup:run >> %s 2>&1\n' "$1" "$MANDAL_BACKUP_LOG"
}

backup_log() {
    printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

# backup_dump db file
backup_dump() {
    local db="$1" file="$2"
    printf '+ dump %s -> %s\n' "$db" "$file"
    dry_run && return 0
    mkdir -p "$(dirname "$file")"
    chmod 0700 "$MANDAL_BACKUP_DIR" "$(dirname "$file")"
    if ! mariadb-dump --single-transaction --quick --routines --triggers --events "$db" | zstd -q -T0 -o "$file"; then
        rm -f "$file"
        return 1
    fi
    chmod 0600 "$file"
}

# Keep the newest N files matching db-*.sql.zst in a directory.
backup_prune_local() {
    local db="$1" keep="$2" dir="$MANDAL_BACKUP_DIR/$1" files count
    [[ -d "$dir" ]] || return 0
    mapfile -t files < <(find "$dir" -maxdepth 1 -type f -name "${db}-*.sql.zst" -printf '%f\n' | sort)
    count=${#files[@]}
    while [[ $count -gt $keep ]]; do
        run_cmd rm -f "$dir/${files[0]}"
        files=("${files[@]:1}")
        count=$((count - 1))
    done
}

backup_upload() {
    local db="$1" file="$2"
    backup_remote_enabled || return 0
    run_cmd rclone_cmd copy "$file" "$(backup_remote_target)/$db/"
}

backup_prune_remote() {
    local db="$1" keep="$2" files count target
    backup_remote_enabled || return 0
    target="$(backup_remote_target)/$db"
    dry_run && {
        printf '+ prune remote %s (keep %s)\n' "$target" "$keep"
        return 0
    }
    mapfile -t files < <(rclone_cmd lsf "$target" 2> /dev/null | grep -E "^${db}-[0-9]{14}\.sql\.zst$" | sort)
    count=${#files[@]}
    while [[ $count -gt $keep ]]; do
        run_cmd rclone_cmd deletefile "$target/${files[0]}"
        files=("${files[@]:1}")
        count=$((count - 1))
    done
}

# backup_run [db]
backup_run() {
    local only="${1:-}" keep_local keep_remote db file failed=0 count=0
    backup_configured || die "backups are not configured; run mandal backup:configure first"
    keep_local="$(backup_conf_get KEEP_LOCAL)"
    keep_remote="$(backup_conf_get KEEP_REMOTE)"
    for db in $(backup_databases); do
        [[ -z "$only" || "$db" == "$only" ]] || continue
        count=$((count + 1))
        file="$MANDAL_BACKUP_DIR/$db/$(backup_file_name "$db")"
        if backup_dump "$db" "$file" && backup_upload "$db" "$file"; then
            backup_log "ok $db $file $(dry_run || stat -c %s "$file" 2> /dev/null || echo 0)"
        else
            backup_log "failed $db"
            failed=$((failed + 1))
            continue
        fi
        backup_prune_local "$db" "${keep_local:-7}"
        backup_prune_remote "$db" "${keep_remote:-30}"
    done
    [[ "$count" -gt 0 ]] || die "no databases to back up${only:+ (no database named $only)}"
    [[ "$failed" -eq 0 ]] || die "$failed backup(s) failed"
}

# JSON: local and remote backups per database.
backup_list_json() {
    local only="${1:-}" db local_files remote_files
    for db in $(backup_databases); do
        [[ -z "$only" || "$db" == "$only" ]] || continue
        local_files="$(find "$MANDAL_BACKUP_DIR/$db" -maxdepth 1 -type f -name "${db}-*.sql.zst" -printf '%f\t%s\t%TY-%Tm-%TdT%TH:%TM:%TSZ\n' 2> /dev/null | sort | json_tsv file size modified | jq 'map(.size |= tonumber)')"
        remote_files='[]'
        if backup_remote_enabled && ! dry_run; then
            remote_files="$({ rclone_cmd lsjson "$(backup_remote_target)/$db" 2> /dev/null || echo '[]'; } | jq 'map({file: .Name, size: .Size, modified: .ModTime})')"
        fi
        jq -n --arg db "$db" --argjson local "$local_files" --argjson remote "$remote_files" '{database: $db, local: $local, remote: $remote}'
    done | jq -s '.'
}

# shellcheck disable=SC2016  # jq filters use $vars on purpose
backup_status_json() {
    local configured=false last_line=""
    backup_configured && configured=true
    [[ -r "$MANDAL_BACKUP_LOG" ]] && last_line="$(tail -n 1 "$MANDAL_BACKUP_LOG" 2> /dev/null || true)"
    jq -n \
        --argjson configured "$configured" \
        --arg schedule "$(backup_conf_get SCHEDULE)" \
        --arg keep_local "$(backup_conf_get KEEP_LOCAL)" \
        --arg keep_remote "$(backup_conf_get KEEP_REMOTE)" \
        --argjson remote "$(backup_remote_enabled && echo true || echo false)" \
        --arg bucket "$(backup_conf_get S3_BUCKET)" \
        --arg path "$(backup_conf_get S3_PATH)" \
        --arg endpoint "$(backup_conf_get S3_ENDPOINT)" \
        --arg last "$last_line" \
        --argjson databases "$(backup_databases | json_lines)" \
        '{
            configured: $configured,
            schedule: $schedule,
            keep_local: ($keep_local | if . == "" then null else tonumber end),
            keep_remote: ($keep_remote | if . == "" then null else tonumber end),
            remote: {enabled: $remote, bucket: $bucket, path: $path, endpoint: $endpoint},
            last_run: (if $last == "" then null else ($last | capture("^(?<at>\\S+) (?<status>\\S+) ?(?<rest>.*)$") // {at: null, status: $last, rest: ""}) end),
            databases: $databases
        }'
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
        backup_remote_enabled || dry_run || die "backup '$name' not found locally and no remote is configured"
        tmp="$(mktemp -d)"
        run_cmd rclone_cmd copy "$(backup_remote_target)/$db/$name" "$tmp/"
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
