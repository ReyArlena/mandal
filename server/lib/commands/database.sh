#!/usr/bin/env bash
#
# db:list / db:create / db:access / db:delete
# db:user:create / db:user:update / db:user:delete
#
# Databases and database users on the server. Users are always local
# (@localhost), since the database only listens on 127.0.0.1, and get all
# privileges on the databases they are given. A database or user that
# belongs to an app is never deleted here (delete the app instead), and
# the app user always keeps access to its own database.

MANDAL_COMMANDS+=("db:list|Databases with their size and users with their access, as JSON|read")
MANDAL_COMMANDS+=("db:create|Create a database (--name=, optionally --user= for each user given access)|write")
MANDAL_COMMANDS+=("db:access|Set which users can use a database (--name=, --user= for each)|write")
MANDAL_COMMANDS+=("db:delete|Delete a database no app uses (--name=)|write")
MANDAL_COMMANDS+=("db:user:create|Create a database user (--name=, --database= for each, password in --from=)|write")
MANDAL_COMMANDS+=("db:user:update|Set a user's databases (--database= for each) and optionally its password (--from=)|write")
MANDAL_COMMANDS+=("db:user:delete|Delete a database user no app uses (--name=)|write")

MANDAL_DB_NAME_PATTERN='^[a-z0-9_]{1,64}$'
MANDAL_DB_USER_PATTERN='^[a-z][a-z0-9_]{0,31}$'
# No quotes, backslashes or whitespace, so a password is safe inside SQL.
MANDAL_DB_PASSWORD_PATTERN='^[A-Za-z0-9!#%+,.:=?@^_~-]{12,128}$'

validate_database() {
    [[ "$1" =~ $MANDAL_DB_NAME_PATTERN ]] || die 2 "invalid database name '$1': use up to 64 lower-case letters, digits or underscores"
    [[ ! "$1" =~ ^(${MANDAL_DB_SYSTEM_SCHEMAS})$ ]] || die 2 "'$1' is a system database"
}

validate_database_user() {
    [[ "$1" =~ $MANDAL_DB_USER_PATTERN ]] || die 2 "invalid user name '$1': use up to 32 lower-case letters, digits or underscores, starting with a letter"
    [[ ! "$1" =~ ^(root|mysql|mariadb\.sys|debian-sys-maint|healthcheck)$ ]] || die 2 "'$1' is a system user"
}

# The app whose database (or database user) this is, if any.
db_app_for() {
    local file
    for file in "$MANDAL_APPS_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        if [[ "$(conf_get "$file" DATABASE 2> /dev/null || true)" == "$1" ]]; then
            basename "$file" .conf
            return 0
        fi
    done
    return 0
}

# The password from an uploaded KEY=value file, which is removed afterwards.
# Sets DB_PASSWORD for the caller.
db_read_password() {
    local from="$1"
    [[ -f "$from" && ! -L "$from" ]] || die 2 "file not found: $from"
    DB_PASSWORD="$(conf_get "$from" PASSWORD 2> /dev/null || true)"
    dry_run || rm -f "$from"
    [[ "$DB_PASSWORD" =~ $MANDAL_DB_PASSWORD_PATTERN ]] || die 2 "the password must be 12 to 128 characters without quotes, backslashes or spaces"
}

db_require_running() {
    dry_run && return 0
    db_client > /dev/null && [[ "$(unit_state mariadb)" == "active" ]] || die "the database server is not running"
}

# Current overview, read once per command.
db_state() {
    DB_STATE="$(db_overview)"
}

db_exists() {
    jq -e --arg name "$1" '.databases | any(.name == $name)' <<< "$DB_STATE" > /dev/null
}

db_user_exists() {
    jq -e --arg name "$1" '.users | any(.user == $name and .host == "localhost")' <<< "$DB_STATE" > /dev/null
}

# Users with access to a database, one per line.
db_users_of() {
    jq -r --arg name "$1" '.users[] | select(.host == "localhost" and (.databases | index($name))) | .user' <<< "$DB_STATE"
}

# Databases a user has access to, one per line.
db_databases_of() {
    jq -r --arg name "$1" '.users[] | select(.user == $name and .host == "localhost") | .databases[]' <<< "$DB_STATE"
}

# db_set_access database wanted-users... - grant and revoke so that exactly
# these users can use the database.
db_set_access() {
    local database="$1" user current
    shift
    current="$(db_users_of "$database")"
    for user in "$@"; do
        grep -qxF -- "$user" <<< "$current" || db_grant "$database" "$user"
    done
    while IFS= read -r user; do
        [[ -n "$user" ]] || continue
        printf '%s\n' "$@" | grep -qxF -- "$user" || db_revoke "$database" "$user"
    done <<< "$current"
}

cmd_db_list() {
    load_all_drivers
    require_root
    local apps='{}' file app database
    for file in "$MANDAL_APPS_DIR"/*.conf; do
        [[ -f "$file" ]] || continue
        app="$(basename "$file" .conf)"
        database="$(conf_get "$file" DATABASE 2> /dev/null || true)"
        [[ -n "$database" ]] && apps="$(jq --arg db "$database" --arg app "$app" '. + {($db): $app}' <<< "$apps")"
    done
    jq --argjson apps "$apps" --argjson status "$(db_status)" '$status + {
        databases: (.databases | map(. + {app: ($apps[.name] // null)})),
        users: (.users | map(. + {app: (if .host == "localhost" then ($apps[.user] // null) else null end)}))
    }' <<< "$(db_overview)"
}

cmd_db_create() {
    local arg name="" users=() user
    load_all_drivers
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --user=*) users+=("${arg#*=}") ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    validate_database "$name"
    for user in "${users[@]}"; do validate_database_user "$user"; done
    require_root
    db_require_running
    db_state
    db_exists "$name" && die "database '$name' already exists"
    for user in "${users[@]}"; do
        db_user_exists "$user" || die "user '$user' does not exist"
    done
    if ! dry_run; then
        with_lock
        audit "db:create $name"
    fi
    db_create "$name"
    for user in "${users[@]}"; do db_grant "$name" "$user"; done
    printf 'Database %s created.\n' "$name"
}

cmd_db_access() {
    local arg name="" users=() user app
    load_all_drivers
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --user=*) users+=("${arg#*=}") ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    validate_database "$name"
    for user in "${users[@]}"; do validate_database_user "$user"; done
    require_root
    db_require_running
    db_state
    db_exists "$name" || die "database '$name' does not exist"
    for user in "${users[@]}"; do
        db_user_exists "$user" || die "user '$user' does not exist"
    done
    app="$(db_app_for "$name")"
    if [[ -n "$app" ]] && ! printf '%s\n' "${users[@]}" | grep -qxF -- "$name"; then
        die 2 "user '$name' must keep access: app '$app' uses this database"
    fi
    if ! dry_run; then
        with_lock
        audit "db:access $name ${users[*]}"
    fi
    db_set_access "$name" "${users[@]}"
    printf 'Access to %s updated.\n' "$name"
}

cmd_db_delete() {
    local arg name="" app
    load_all_drivers
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    validate_database "$name"
    require_root
    app="$(db_app_for "$name")"
    [[ -z "$app" ]] || die 2 "app '$app' uses database '$name'; delete the app instead"
    db_require_running
    db_state
    db_exists "$name" || die "database '$name' does not exist"
    if ! dry_run; then
        with_lock
        audit "db:delete $name"
    fi
    # MariaDB keeps database grants after a drop; a later database with
    # the same name would silently give these users access again.
    db_set_access "$name"
    db_drop "$name"
    printf 'Database %s deleted.\n' "$name"
}

cmd_db_user_create() {
    local arg name="" from="" databases=() database
    load_all_drivers
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --database=*) databases+=("${arg#*=}") ;;
            --from=*) from="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg' (the password is read from --from=<file>)" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    [[ -n "$from" ]] || die 64 "--from is required (a file with PASSWORD=...)"
    validate_database_user "$name"
    for database in "${databases[@]}"; do validate_database "$database"; done
    require_root
    db_read_password "$from"
    db_require_running
    db_state
    db_user_exists "$name" && die "user '$name' already exists"
    for database in "${databases[@]}"; do
        db_exists "$database" || die "database '$database' does not exist"
    done
    if ! dry_run; then
        with_lock
        audit "db:user:create $name"
    fi
    db_user_create "$name" "$DB_PASSWORD"
    for database in "${databases[@]}"; do db_grant "$database" "$name"; done
    printf 'User %s created.\n' "$name"
}

cmd_db_user_update() {
    local arg name="" from="" databases=() database current app
    load_all_drivers
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --database=*) databases+=("${arg#*=}") ;;
            --from=*) from="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg' (the password is read from --from=<file>)" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    validate_database_user "$name"
    for database in "${databases[@]}"; do validate_database "$database"; done
    require_root
    app="$(db_app_for "$name")"
    if [[ -n "$from" ]]; then
        [[ -z "$app" ]] || { dry_run || rm -f "$from"; die 2 "app '$app' signs in as '$name'; its password lives in the app's .env"; }
        db_read_password "$from"
    fi
    if [[ -n "$app" ]] && ! printf '%s\n' "${databases[@]}" | grep -qxF -- "$name"; then
        die 2 "user '$name' must keep access to database '$name': app '$app' uses it"
    fi
    db_require_running
    db_state
    db_user_exists "$name" || die "user '$name' does not exist"
    for database in "${databases[@]}"; do
        db_exists "$database" || die "database '$database' does not exist"
    done
    if ! dry_run; then
        with_lock
        audit "db:user:update $name ${databases[*]}"
    fi
    [[ -z "$from" ]] || db_user_password "$name" "$DB_PASSWORD"
    current="$(db_databases_of "$name")"
    for database in "${databases[@]}"; do
        grep -qxF -- "$database" <<< "$current" || db_grant "$database" "$name"
    done
    while IFS= read -r database; do
        [[ -n "$database" ]] || continue
        printf '%s\n' "${databases[@]}" | grep -qxF -- "$database" || db_revoke "$database" "$name"
    done <<< "$current"
    printf 'User %s updated.\n' "$name"
}

cmd_db_user_delete() {
    local arg name="" app
    load_all_drivers
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    validate_database_user "$name"
    require_root
    app="$(db_app_for "$name")"
    [[ -z "$app" ]] || die 2 "app '$app' signs in as '$name'; delete the app instead"
    db_require_running
    db_state
    db_user_exists "$name" || die "user '$name' does not exist"
    if ! dry_run; then
        with_lock
        audit "db:user:delete $name"
    fi
    db_user_drop "$name"
    printf 'User %s deleted.\n' "$name"
}
