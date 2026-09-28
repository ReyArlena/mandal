#!/usr/bin/env bash
#
# backup:configure / backup:run / backup:list / backup:status / backup:restore

MANDAL_COMMANDS+=("backup:configure|Set the backup schedule, retention and S3-compatible remote|write")
MANDAL_COMMANDS+=("backup:run|Back up every database (or one with --database=)|write")
MANDAL_COMMANDS+=("backup:list|List local and remote backups as JSON|read")
MANDAL_COMMANDS+=("backup:status|Show the backup configuration and last run as JSON|read")
MANDAL_COMMANDS+=("backup:restore|Restore a backup into a new database (--database= --file=)|write")

cmd_backup_configure() {
    local arg from="" schedule="30 2 * * *" keep_local=7 keep_remote=30 remote=false
    local bucket="" path="" endpoint="" region="auto" key="" secret=""
    for arg in "$@"; do
        case "$arg" in
            --from=*) from="${arg#*=}" ;;
            --schedule=*) schedule="${arg#*=}" ;;
            --keep-local=*) keep_local="${arg#*=}" ;;
            --keep-remote=*) keep_remote="${arg#*=}" ;;
            --no-remote) remote=false ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg' (S3 settings are read from --from=<file>)" ;;
        esac
    done
    # Secrets come from a file (uploaded by the panel) so they never appear in arguments or the audit log.
    if [[ -n "$from" ]]; then
        [[ -f "$from" && ! -L "$from" ]] || die 2 "file not found: $from"
        schedule="$(conf_get "$from" SCHEDULE 2> /dev/null || echo "$schedule")"
        keep_local="$(conf_get "$from" KEEP_LOCAL 2> /dev/null || echo "$keep_local")"
        keep_remote="$(conf_get "$from" KEEP_REMOTE 2> /dev/null || echo "$keep_remote")"
        remote="$(conf_get "$from" REMOTE 2> /dev/null || echo false)"
        bucket="$(conf_get "$from" S3_BUCKET 2> /dev/null || true)"
        path="$(conf_get "$from" S3_PATH 2> /dev/null || true)"
        endpoint="$(conf_get "$from" S3_ENDPOINT 2> /dev/null || true)"
        region="$(conf_get "$from" S3_REGION 2> /dev/null || echo auto)"
        key="$(conf_get "$from" S3_KEY 2> /dev/null || true)"
        secret="$(conf_get "$from" S3_SECRET 2> /dev/null || true)"
    fi
    validate_schedule "$schedule"
    [[ "$keep_local" =~ ^[0-9]{1,3}$ ]] || die 2 "--keep-local must be a number"
    [[ "$keep_remote" =~ ^[0-9]{1,3}$ ]] || die 2 "--keep-remote must be a number"
    if [[ "$remote" == "true" ]]; then
        [[ "$bucket" =~ ^[a-z0-9][a-z0-9.-]{1,62}$ ]] || die 2 "invalid S3 bucket '$bucket'"
        [[ -z "$path" || "$path" =~ ^[A-Za-z0-9._/-]{1,200}$ ]] || die 2 "invalid S3 path '$path'"
        [[ "$endpoint" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?(/.*)?$ ]] || die 2 "invalid S3 endpoint '$endpoint' (https URL expected)"
        [[ "$region" =~ ^[A-Za-z0-9-]{1,32}$ ]] || die 2 "invalid S3 region '$region'"
        [[ -n "$key" && -n "$secret" ]] || die 2 "S3 access key and secret are required"
        [[ "$key$secret" != *$'\n'* ]] || die 2 "invalid S3 credentials"
    else
        remote=false
    fi

    require_root
    load_all_drivers
    apt_install rclone zstd > /dev/null
    if ! dry_run; then
        with_lock
        audit "backup:configure"
    fi
    step "Configuring backups"
    write_file "$MANDAL_BACKUP_CONF" 0600 < <(backup_render_conf "$schedule" "$keep_local" "$keep_remote" "$remote" "$bucket" "$path" "$endpoint" "$region")
    if [[ "$remote" == "true" ]]; then
        write_file "$MANDAL_RCLONE_CONF" 0600 < <(backup_render_rclone "$endpoint" "$region" "$key" "$secret")
        if ! dry_run; then
            printf '+ checking the remote\n'
            rclone_cmd lsd "${MANDAL_RCLONE_REMOTE}:${bucket}" > /dev/null || die "could not list the bucket '$bucket' with the given credentials"
        fi
    else
        run_cmd rm -f "$MANDAL_RCLONE_CONF"
    fi
    write_file "$MANDAL_BACKUP_CRON" 0644 < <(backup_render_cron "$schedule")
    run_cmd install -d -m 0700 "$MANDAL_BACKUP_DIR"
    dry_run || rm -f "$from"
    printf '\nBackups run at "%s", keeping %s local and %s remote copies%s.\n' "$schedule" "$keep_local" "$keep_remote" "$([[ "$remote" == "true" ]] && echo " in $bucket" || echo " (no remote)")"
}

cmd_backup_run() {
    local arg only=""
    for arg in "$@"; do
        case "$arg" in
            --database=*) only="${arg#*=}"; validate_db_name "$only" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    load_all_drivers
    if ! dry_run; then
        mkdir -p "$(dirname "$MANDAL_BACKUP_LOCK")" "$MANDAL_LOG_DIR"
        exec 8> "$MANDAL_BACKUP_LOCK"
        flock -n 8 || die 75 "a backup is already running"
        audit "backup:run ${only:-all}"
    fi
    backup_run "$only" 2>&1 | tee -a "$(dry_run && echo /dev/null || echo "$MANDAL_BACKUP_LOG")"
    return "${PIPESTATUS[0]}"
}

cmd_backup_list() {
    local arg only=""
    for arg in "$@"; do
        case "$arg" in
            --database=*) only="${arg#*=}"; validate_db_name "$only" ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    load_all_drivers
    backup_list_json "$only"
}

cmd_backup_status() {
    require_root
    load_all_drivers
    backup_status_json
}

cmd_backup_restore() {
    local arg db="" file="" into=""
    for arg in "$@"; do
        case "$arg" in
            --database=*) db="${arg#*=}" ;;
            --file=*) file="${arg#*=}" ;;
            --into=*) into="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$db" && -n "$file" ]] || die 64 "--database and --file are required"
    require_root
    load_all_drivers
    if ! dry_run; then
        with_lock
        audit "backup:restore $db $file ${into:+into=$into}"
    fi
    backup_restore "$db" "$file" "$into"
}
