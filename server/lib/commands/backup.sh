#!/usr/bin/env bash
#
# backup:remote / backup:remote-list - named S3-compatible remotes
# backup:configure / backup:run / backup:list / backup:status /
# backup:restore / backup:delete - database backups

MANDAL_COMMANDS+=("backup:remote|Add or update an S3-compatible remote from --from=<file> (--name=), or --remove it|write")
MANDAL_COMMANDS+=("backup:remote-list|List the remotes and which backups use them as JSON|read")
MANDAL_COMMANDS+=("backup:configure|Set database backup destinations from --from=<file>, or write the --defaults once|write")
MANDAL_COMMANDS+=("backup:run|Back up every database (or one with --database=) to --destination=local, remote or all|write")
MANDAL_COMMANDS+=("backup:list|List local and remote database backups as JSON|read")
MANDAL_COMMANDS+=("backup:status|Show the backup configuration and last runs as JSON|read")
MANDAL_COMMANDS+=("backup:restore|Restore a backup into a new database (--database= --file=)|write")
MANDAL_COMMANDS+=("backup:delete|Delete one database backup (--database= --file= --where=local or remote)|write")

cmd_backup_remote() {
    local arg name="" from="" remove=false force=false
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --from=*) from="${arg#*=}" ;;
            --remove) remove=true ;;
            --force) force=true ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    validate_remote_name "$name"
    require_root
    if [[ "$remove" == true ]]; then
        backup_remote_remove "$name" "$force"
        return 0
    fi
    [[ -n "$from" ]] || die 64 "--from=<file> is required (LABEL, ENDPOINT, REGION, BUCKET, PATH, KEY, SECRET)"
    [[ -f "$from" && ! -L "$from" ]] || die 2 "file not found: $from"
    local label endpoint region bucket path key secret
    label="$(conf_get "$from" LABEL 2> /dev/null || echo "$name")"
    endpoint="$(conf_get "$from" ENDPOINT 2> /dev/null || true)"
    region="$(conf_get "$from" REGION 2> /dev/null || echo auto)"
    bucket="$(conf_get "$from" BUCKET 2> /dev/null || true)"
    path="$(conf_get "$from" PATH 2> /dev/null || true)"
    key="$(conf_get "$from" KEY 2> /dev/null || true)"
    secret="$(conf_get "$from" SECRET 2> /dev/null || true)"
    # Removed straight away, so a refused remote leaves no secrets behind.
    dry_run || rm -f "$from"
    [[ "$label" =~ ^[^$'\n']{1,100}$ ]] || die 2 "invalid LABEL"
    [[ "$endpoint" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?(/.*)?$ ]] || die 2 "invalid S3 endpoint '$endpoint' (https URL expected)"
    [[ "$region" =~ ^[A-Za-z0-9-]{1,32}$ ]] || die 2 "invalid S3 region '$region'"
    [[ "$bucket" =~ ^[a-z0-9][a-z0-9.-]{1,62}$ ]] || die 2 "invalid S3 bucket '$bucket'"
    path="${path#/}"
    path="${path%/}"
    [[ -z "$path" || "$path" =~ ^[A-Za-z0-9._/-]{1,200}$ ]] || die 2 "invalid S3 path '$path'"
    [[ -n "$key" && -n "$secret" ]] || die 2 "S3 access key and secret are required"
    [[ "$key$secret" != *$'\n'* && "$key$secret" != *$'\t'* ]] || die 2 "invalid S3 credentials"

    if ! dry_run; then
        with_lock
        audit "backup:remote $name"
    fi
    step "Saving remote $name"
    apt_install rclone zstd > /dev/null
    write_file "$(remote_conf_file "$name")" 0644 < <(backup_render_remote_conf "$label" "$endpoint" "$region" "$bucket" "$path")
    write_file "$(rclone_conf_file "$name")" 0600 < <(backup_render_rclone "$name" "$endpoint" "$region" "$key" "$secret")
    if ! dry_run; then
        printf '+ checking the bucket\n'
        local output
        if ! output="$(rclone_cmd "$name" lsd "${name}:${bucket}" 2>&1 > /dev/null)"; then
            die "remote '$name' was saved, but listing the bucket '$bucket' at $endpoint failed: $(printf '%s' "$output" | tail -n 1). Check the endpoint, bucket and keys, then save the destination again"
        fi
    fi
    printf '\nRemote %s points at %s in bucket %s%s.\n' "$name" "$endpoint" "$bucket" "${path:+ under $path/}"
}

# backup_remote_remove slug force - refused while a backup uses the remote,
# unless forced: then those backups lose the remote, and one left without
# any destination is disabled as well.
# shellcheck disable=SC2016
backup_remote_remove() {
    local slug="$1" force="$2" used target detached=() disabled=()
    remote_exists "$slug" || [[ -f "$(rclone_conf_file "$slug")" ]] || die "remote '$slug' does not exist"
    used="$(remote_used_by "$slug" | tr '\n' ' ')"
    used="${used% }"
    if [[ -n "$used" && "$force" != true ]]; then
        die 2 "remote '$slug' is used by ${used// /, }; change those backups first or pass --force to detach them"
    fi
    if ! dry_run; then
        with_lock
        audit "backup:remote $slug remove${used:+ (detached: $used)}"
    fi
    step "Removing remote $slug"
    for target in $used; do
        backup_target_load "$target"
        BACKUP_REMOTE=""
        BACKUP_REMOTE_PATH=""
        detached+=("$target")
        if [[ "$BACKUP_LOCAL" != true ]]; then
            BACKUP_ENABLED=false
            disabled+=("$target")
        fi
        backup_write_target "$target"
    done
    run_cmd rm -f "$(remote_conf_file "$slug")" "$(rclone_conf_file "$slug")"
    backup_write_cron
    jq -n -c \
        --argjson detached "$(printf '%s\n' "${detached[@]:-}" | json_lines)" \
        --argjson disabled "$(printf '%s\n' "${disabled[@]:-}" | json_lines)" \
        '{removed: true, detached: $detached, disabled: $disabled}'
}

cmd_backup_remote_list() {
    [[ $# -eq 0 ]] || die 64 "unknown option '$1'"
    require_root
    remote_list_json
}

cmd_backup_configure() {
    local arg from="" defaults=false schedule="" keep_local="" keep_remote=""
    for arg in "$@"; do
        case "$arg" in
            --from=*) from="${arg#*=}" ;;
            --defaults) defaults=true ;;
            --schedule=*) schedule="${arg#*=}" ;;
            --keep-local=*) keep_local="${arg#*=}" ;;
            --keep-remote=*) keep_remote="${arg#*=}" ;;
            --no-remote) ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg' (destinations are read from --from=<file>)" ;;
        esac
    done
    require_root
    load_all_drivers
    if [[ "$defaults" == true ]]; then
        if ! dry_run; then
            with_lock
            audit "backup:configure defaults"
        fi
        step "Database backups"
        backup_configure_defaults
        return 0
    fi
    if [[ -n "$from" ]]; then
        [[ -f "$from" && ! -L "$from" ]] || die 2 "file not found: $from"
        backup_target_read "$from"
        # The panel uploads the settings; nothing secret is in them, but the
        # file is single use like every other upload.
        dry_run || rm -f "$from"
    else
        # The shorthands describe a local-only schedule, as before remotes existed.
        # shellcheck disable=SC2034  # read by backup_target_validate and backup_write_target
        BACKUP_ENABLED=true BACKUP_LOCAL=true BACKUP_LOCAL_SCHEDULE="$schedule" BACKUP_LOCAL_KEEP="$keep_local" BACKUP_REMOTE="" BACKUP_REMOTE_SCHEDULE="$schedule" BACKUP_REMOTE_KEEP="$keep_remote" BACKUP_REMOTE_PATH="" BACKUP_PAUSE=""
        backup_target_defaults
    fi
    # shellcheck disable=SC2034  # databases never pause anything
    BACKUP_PAUSE=false
    backup_target_validate

    if ! dry_run; then
        with_lock
        audit "backup:configure"
    fi
    step "Configuring database backups"
    apt_install zstd > /dev/null
    backup_write_target databases
    backup_write_cron databases
    run_cmd install -d -m 0700 "$MANDAL_BACKUP_DIR"
    backup_describe
}

# A one-line summary of the BACKUP_* destinations for the end of a configure.
backup_describe() {
    if [[ "$BACKUP_ENABLED" != true ]]; then
        printf '\nBackups are disabled; nothing runs on a schedule.\n'
        return 0
    fi
    printf '\nBackups run%s%s.\n' \
        "$([[ "$BACKUP_LOCAL" == true ]] && printf ' locally at "%s" keeping %s' "$BACKUP_LOCAL_SCHEDULE" "$BACKUP_LOCAL_KEEP" || printf ' with no local copy')" \
        "$([[ -n "$BACKUP_REMOTE" ]] && printf ' and to %s at "%s" keeping %s' "$BACKUP_REMOTE" "$BACKUP_REMOTE_SCHEDULE" "$BACKUP_REMOTE_KEEP" || printf ' (no remote)')"
}

cmd_backup_run() {
    local arg only="" destination=all
    for arg in "$@"; do
        case "$arg" in
            --database=*) only="${arg#*=}"; validate_db_name "$only" ;;
            --destination=*) destination="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$destination" =~ ^(local|remote|all)$ ]] || die 2 "--destination must be local, remote or all"
    require_root
    load_all_drivers
    if ! dry_run; then
        backup_take_lock
        audit "backup:run ${only:-all} $destination"
    fi
    backup_run "$only" "$destination" 2>&1 | tee -a "$(dry_run && echo /dev/null || echo "$MANDAL_BACKUP_LOG")"
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

cmd_backup_delete() {
    local arg db="" file="" where=""
    for arg in "$@"; do
        case "$arg" in
            --database=*) db="${arg#*=}" ;;
            --file=*) file="${arg#*=}" ;;
            --where=*) where="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$db" && -n "$file" ]] || die 64 "--database and --file are required"
    [[ "$where" =~ ^(local|remote)$ ]] || die 64 "--where must be local or remote"
    require_root
    if ! dry_run; then
        with_lock
        audit "backup:delete $db $file $where"
    fi
    backup_delete "$db" "$file" "$where"
}
