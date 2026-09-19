#!/usr/bin/env bash
#
# Database role: MariaDB.

MANDAL_DB_SYSTEM_SCHEMAS='information_schema|performance_schema|mysql|sys'

db_driver() {
    echo mariadb
}

db_client() {
    if has_cmd mariadb; then
        echo mariadb
    elif has_cmd mysql; then
        echo mysql
    else
        return 1
    fi
}

db_installed() {
    package_installed mariadb-server || has_cmd mariadbd
}

db_version() {
    if has_cmd mariadbd; then
        mariadbd --version 2> /dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n 1
    else
        package_version mariadb-server | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n 1 || true
    fi
}

# JSON: {driver, installed, active, version}
db_status() {
    local installed=false active version=""
    if db_installed; then
        installed=true
        version="$(db_version || true)"
    fi
    active="$(unit_state mariadb)"
    jq -n --arg driver "$(db_driver)" --argjson installed "$installed" --arg active "$active" --arg version "$version" \
        '{driver: $driver, installed: $installed, active: $active, version: $version}'
}

# JSON: {databases: [...], users: [{user, host}]}
db_list() {
    local client
    if ! client="$(db_client)" || [[ "$(unit_state mariadb)" != "active" ]]; then
        echo '{"databases": [], "users": []}'
        return 0
    fi
    local databases users
    databases="$("$client" -N -B -e 'SHOW DATABASES' 2> /dev/null | grep -vE "^(${MANDAL_DB_SYSTEM_SCHEMAS})\$" | json_lines || echo '[]')"
    users="$("$client" -N -B -e "SELECT user, host FROM mysql.user WHERE user NOT IN ('root', 'mariadb.sys', 'mysql', 'debian-sys-maint', 'healthcheck') AND user <> ''" 2> /dev/null | json_tsv user host || echo '[]')"
    jq -n --argjson databases "$databases" --argjson users "$users" '{databases: $databases, users: $users}'
}
