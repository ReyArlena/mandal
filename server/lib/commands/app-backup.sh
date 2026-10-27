#!/usr/bin/env bash
#
# app:backup-tree / app:backup-configure / app:backup / app:backup-list /
# app:backup-delete / app:backup-get - archives of an app's files: a Docker
# app's project directory, or a Laravel app's shared/ directory (the only
# part of it that is not in git).

MANDAL_COMMANDS+=("app:backup-tree|List an app's backup root two levels deep with sizes as JSON (--name=)|read")
MANDAL_COMMANDS+=("app:backup-configure|Set an app's backup destinations and excludes from --from=<file> (--name=)|write")
MANDAL_COMMANDS+=("app:backup|Archive an app's files (--name=) to --destination=local, remote or all|write")
MANDAL_COMMANDS+=("app:backup-list|Show an app's backup settings, last run and archives as JSON (--name=)|read")
MANDAL_COMMANDS+=("app:backup-delete|Delete one app archive (--name= --file= --where=local or remote)|write")
MANDAL_COMMANDS+=("app:backup-get|Write one app archive to stdout (--name= --file=)|read")

MANDAL_APP_ARCHIVE_PATTERN='^[a-z][a-z0-9-]{1,31}-[0-9]{14}\.tar\.zst$'

app_backup_type() {
    conf_get "$(app_conf_path "$1")" TYPE 2> /dev/null || echo laravel
}

# The directory an app backup covers.
app_backup_root() {
    if [[ "$(app_backup_type "$1")" == docker ]]; then
        docker_app_dir "$1"
    else
        printf '%s/shared\n' "$(app_home "$1")"
    fi
}

app_backup_parse_name() {
    local arg
    APP_BACKUP_NAME=""
    for arg in "$@"; do
        case "$arg" in
            --name=*) APP_BACKUP_NAME="${arg#*=}" ;;
        esac
    done
    [[ -n "$APP_BACKUP_NAME" ]] || die 64 "--name is required"
    validate_name "$APP_BACKUP_NAME"
    require_root
    app_exists "$APP_BACKUP_NAME" || die "app '$APP_BACKUP_NAME' does not exist"
}

validate_archive_name() {
    [[ "$2" =~ $MANDAL_APP_ARCHIVE_PATTERN && "$2" == "$1-"* ]] || die 2 "invalid archive name '$2'"
}

# An exclude line is a path or glob relative to the backup root.
validate_exclude_line() {
    local line="$1"
    [[ ${#line} -ge 1 && ${#line} -le 200 ]] || die 2 "exclude lines must be 1-200 characters"
    [[ "$line" != /* ]] || die 2 "exclude '$line' must be relative to the app directory (no leading slash)"
    [[ "/$line/" != *"/../"* && "$line" != ".." ]] || die 2 "exclude '$line' may not contain '..'"
}

# ---------------------------------------------------------------------------
# Tree
# ---------------------------------------------------------------------------

# Two levels of the backup root with sizes, from one find for the types and
# one du pass for the sizes (per-directory du would walk big trees twice).
# shellcheck disable=SC2016
app_backup_tree_json() {
    local name="$1" root
    root="$(app_backup_root "$name")"
    if [[ ! -d "$root" ]]; then
        jq -n --arg root "$root" '{root: $root, entries: []}'
        return 0
    fi
    {
        find "$root" -mindepth 1 -maxdepth 2 -printf 'T\t%P\t%y\n' 2> /dev/null || true
        du -ab --max-depth=2 "$root" 2> /dev/null | awk -F'\t' -v root="$root/" 'index($2, root) == 1 {print "S\t" substr($2, length(root) + 1) "\t" $1}' || true
    } | jq -R -s --arg root "$root" '
        split("\n") | map(select(length > 0) | split("\t"))
        | (map(select(.[0] == "S")) | map({key: .[1], value: (.[2] | tonumber)}) | from_entries) as $sizes
        | map(select(.[0] == "T") | {path: .[1], type: (if .[2] == "d" then "dir" else "file" end), size: ($sizes[.[1]] // 0)})
        | map(. + {parent: (if (.path | contains("/")) then (.path | split("/") | .[0]) else null end)})
        | (map(select(.parent != null)) | group_by(.parent) | map({key: .[0].parent, value: (map(del(.parent)) | sort_by(.path))}) | from_entries) as $children
        | {root: $root, entries: (map(select(.parent == null)) | sort_by(.path) | map({path, type, size, children: ($children[.path] // [])}))}
    '
}

cmd_app_backup_tree() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    app_backup_parse_name "$@"
    app_backup_tree_json "$APP_BACKUP_NAME"
}

# ---------------------------------------------------------------------------
# Configure
# ---------------------------------------------------------------------------

cmd_app_backup_configure() {
    local arg from="" line excludes=()
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --from=*) from="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    app_backup_parse_name "$@"
    local name="$APP_BACKUP_NAME"
    [[ -n "$from" ]] || die 64 "--from=<file> is required"
    [[ -f "$from" && ! -L "$from" ]] || die 2 "file not found: $from"
    if [[ "$(tr -d '\000' < "$from" | wc -c)" -ne "$(wc -c < "$from")" ]]; then
        die 2 "the settings file contains binary data"
    fi
    backup_target_read "$from"
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" == EXCLUDE=* ]] || continue
        line="${line#EXCLUDE=}"
        validate_exclude_line "$line"
        excludes+=("$line")
    done < "$from"
    dry_run || rm -f "$from"
    [[ "$(app_backup_type "$name")" == docker ]] || BACKUP_PAUSE=false
    backup_target_validate

    if ! dry_run; then
        with_lock
        audit "app:backup-configure $name"
    fi
    step "Configuring backups for $name"
    apt_install zstd > /dev/null
    backup_write_target "app:$name"
    write_file "$(app_backup_exclude_file "$name")" 0644 < <(printf '%s\n' "${excludes[@]:-}" | grep -v '^$' || true)
    backup_write_cron "app:$name"
    run_cmd install -d -m 0700 "$MANDAL_APP_BACKUP_DIR"
    backup_describe
}

# ---------------------------------------------------------------------------
# Run
# ---------------------------------------------------------------------------

# Turn the stored exclude lines into tar patterns. Members are archived as
# ./<path>, so every pattern gets that prefix; with --anchored it then
# matches from the root only, and --wildcards-match-slash lets * span
# directories so "**/backups/*.zip" reaches any depth. A pattern starting
# with **/ is also emitted without it so it matches at the root as well,
# and a trailing slash is dropped because tar matches the bare name.
app_backup_tar_patterns() {
    local line
    [[ -r "$1" ]] || return 0
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%/}"
        [[ -n "$line" ]] || continue
        printf './%s\n' "$line"
        [[ "$line" == "**/"* ]] && printf './%s\n' "${line#\*\*/}"
    done < "$1"
    return 0
}

# app_backup_archive name root file - tar never follows symlinks (no -h),
# so a link out of the root is stored as a link. Exit status 1 from tar
# means a file changed while it was read, which is normal for a live app.
app_backup_archive() {
    local name="$1" root="$2" file="$3" patterns status=0
    patterns="$(mktemp)"
    app_backup_tar_patterns "$(app_backup_exclude_file "$name")" > "$patterns"
    backup_prepare_dir "$MANDAL_APP_BACKUP_DIR"
    backup_prepare_dir "$(dirname "$file")"
    printf '+ tar --zstd --anchored --wildcards --wildcards-match-slash --exclude-from=%s -C %s -cf %s .\n' "$(app_backup_exclude_file "$name")" "$root" "$file"
    if ! dry_run; then
        tar --zstd --anchored --wildcards --wildcards-match-slash --exclude-from="$patterns" -C "$root" -cf "$file" . || status=$?
        if [[ "$status" -gt 1 ]]; then
            rm -f "$file"
        else
            chmod 0600 "$file"
            status=0
        fi
    fi
    rm -f "$patterns"
    return "$status"
}

# Refuse when the filesystem could not take another archive the size of
# the last one and still keep 256 MB free.
app_backup_check_space() {
    local name="$1" dir="$MANDAL_APP_BACKUP_DIR/$1" newest size free
    [[ -d "$dir" ]] || return 0
    newest="$(find "$dir" -maxdepth 1 -type f -name "${name}-*.tar.zst" -printf '%f\n' | sort | tail -n 1)"
    [[ -n "$newest" ]] || return 0
    size="$(stat -c %s "$dir/$newest")"
    free="$(df --output=avail -B1 "$dir" | tail -n 1 | tr -d ' ')"
    [[ "$free" -ge $((size + MANDAL_BACKUP_FREE_MARGIN)) ]] || die "not enough free space to back up $name: $((free / 1048576)) MB free, the last archive was $((size / 1048576)) MB and 256 MB must stay free"
}

# app_backup_run name destination
app_backup_run() {
    local name="$1" destination="$2" root file paused=false
    backup_target_configured "app:$name" || die "backups are not set up for app '$name'; configure them on the app page first"
    backup_resolve "app:$name" "$destination"
    root="$(app_backup_root "$name")"
    if [[ ! -d "$root" ]]; then
        backup_log "failed app $name missing $root"
        die "nothing to back up: $root does not exist (deploy the app first)"
    fi
    app_backup_check_space "$name"
    file="$(backup_target_dir "$MANDAL_APP_BACKUP_DIR" "$name")/${name}-$(date -u +%Y%m%d%H%M%S).tar.zst"
    # Paused containers cannot change files half way through the archive;
    # the trap unpauses them even when the archive step dies.
    if [[ "$BACKUP_PAUSE" == true && "$(app_backup_type "$name")" == docker ]] && { dry_run || docker_app_running "$name"; }; then
        # shellcheck disable=SC2064  # the name is meant to be expanded now
        trap "docker_compose '$name' unpause" EXIT
        docker_compose "$name" pause
        paused=true
    fi
    if ! app_backup_archive "$name" "$root" "$file"; then
        backup_log "failed app $name"
        [[ "$paused" == false ]] || { docker_compose "$name" unpause; trap - EXIT; }
        die "archiving $root failed"
    fi
    [[ "$paused" == false ]] || { docker_compose "$name" unpause; trap - EXIT; }
    backup_log "ok app $name $file $(dry_run || stat -c %s "$file" 2> /dev/null || echo 0)"
    backup_store "$MANDAL_APP_BACKUP_DIR" "$name" "$file" "^${name}-[0-9]{14}\.tar\.zst$" "app $name" \
        || die "the remote '$BACKUP_REMOTE' could not be reached; the local copy was kept"
}

cmd_app_backup() {
    local arg destination=all
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --destination=*) destination="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$destination" =~ ^(local|remote|all)$ ]] || die 2 "--destination must be local, remote or all"
    app_backup_parse_name "$@"
    if ! dry_run; then
        backup_take_lock
        audit "app:backup $APP_BACKUP_NAME $destination"
    fi
    app_backup_run "$APP_BACKUP_NAME" "$destination" 2>&1 | tee -a "$(dry_run && echo /dev/null || echo "$MANDAL_BACKUP_LOG")"
    return "${PIPESTATUS[0]}"
}

# ---------------------------------------------------------------------------
# List, delete, get
# ---------------------------------------------------------------------------

# shellcheck disable=SC2016
app_backup_list_json() {
    local name="$1" configured=false config local_files remote_files='[]'
    backup_target_configured "app:$name" && configured=true
    backup_target_load "app:$name"
    config="$(backup_target_json "app:$name")"
    if [[ "$configured" == false ]]; then
        # Defaults for the settings form: off, and pausing on for Docker apps.
        config="$(jq --argjson docker "$([[ "$(app_backup_type "$name")" == docker ]] && echo true || echo false)" '.enabled = false | .pause = $docker' <<< "$config")"
    fi
    local_files="$({ find "$MANDAL_APP_BACKUP_DIR/$name" -maxdepth 1 -type f -name "${name}-*.tar.zst" -printf '%f\t%s\t%TY-%Tm-%TdT%TH:%TM:%TSZ\n' 2> /dev/null || true; } | json_tsv file size modified | jq 'map(.size |= tonumber | .where = "local")')"
    if [[ -n "$BACKUP_REMOTE" ]] && remote_usable "$BACKUP_REMOTE" && ! dry_run; then
        remote_files="$({ rclone_cmd "$BACKUP_REMOTE" lsjson "$(remote_target "$BACKUP_REMOTE" "$BACKUP_REMOTE_PATH" "$name")" 2> /dev/null || echo '[]'; } | jq 'map({file: .Name, size: .Size, modified: .ModTime, where: "remote"})')"
    fi
    jq -n \
        --argjson config "$config" \
        --argjson configured "$configured" \
        --argjson last_run "$(backup_last_run_json "app:$name")" \
        --argjson local "$local_files" \
        --argjson remote "$remote_files" \
        '{config: $config, configured: $configured, last_run: $last_run, backups: (($local + $remote) | sort_by(.file, .where) | reverse)}'
}

cmd_app_backup_list() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    app_backup_parse_name "$@"
    app_backup_list_json "$APP_BACKUP_NAME"
}

cmd_app_backup_delete() {
    local arg file="" where=""
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --file=*) file="${arg#*=}" ;;
            --where=*) where="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$file" ]] || die 64 "--file is required"
    [[ "$where" =~ ^(local|remote)$ ]] || die 64 "--where must be local or remote"
    app_backup_parse_name "$@"
    local name="$APP_BACKUP_NAME"
    validate_archive_name "$name" "$file"
    if ! dry_run; then
        with_lock
        audit "app:backup-delete $name $file $where"
    fi
    if [[ "$where" == local ]]; then
        [[ -f "$MANDAL_APP_BACKUP_DIR/$name/$file" ]] || dry_run || die "archive '$file' not found locally"
        run_cmd rm -f "$MANDAL_APP_BACKUP_DIR/$name/$file"
    else
        backup_target_load "app:$name"
        [[ -n "$BACKUP_REMOTE" ]] || die "no remote is set for app '$name'"
        run_cmd rclone_cmd "$BACKUP_REMOTE" deletefile "$(remote_target "$BACKUP_REMOTE" "$BACKUP_REMOTE_PATH" "$name")/$file"
    fi
    printf '\nDeleted %s (%s).\n' "$file" "$where"
}

# Only the archive bytes go to stdout: the panel streams them to the browser.
cmd_app_backup_get() {
    local arg file=""
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --file=*) file="${arg#*=}" ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$file" ]] || die 64 "--file is required"
    app_backup_parse_name "$@"
    local name="$APP_BACKUP_NAME"
    validate_archive_name "$name" "$file"
    audit "app:backup-get $name $file"
    if [[ -f "$MANDAL_APP_BACKUP_DIR/$name/$file" ]]; then
        cat "$MANDAL_APP_BACKUP_DIR/$name/$file"
        return 0
    fi
    backup_target_load "app:$name"
    if [[ -z "$BACKUP_REMOTE" ]] || ! remote_usable "$BACKUP_REMOTE"; then
        die "archive '$file' not found locally and no remote is set"
    fi
    rclone_cmd "$BACKUP_REMOTE" cat "$(remote_target "$BACKUP_REMOTE" "$BACKUP_REMOTE_PATH" "$name")/$file"
}

# Called by app:delete: the target conf, excludes and cron lines always go,
# the local archives only when the app's files go too.
app_backup_cleanup() {
    local name="$1" keep_files="$2"
    [[ -f "$(backup_target_file "app:$name")" || -f "$(app_backup_exclude_file "$name")" ]] && run_cmd rm -f "$(backup_target_file "app:$name")" "$(app_backup_exclude_file "$name")"
    backup_write_cron "" "app:$name"
    if [[ "$keep_files" != true && -d "$MANDAL_APP_BACKUP_DIR/$name" ]]; then
        run_cmd rm -rf "$MANDAL_APP_BACKUP_DIR/$name"
    fi
    return 0
}
