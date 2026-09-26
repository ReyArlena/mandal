#!/usr/bin/env bash
#
# Database role: MariaDB.

MANDAL_DB_SYSTEM_SCHEMAS='information_schema|performance_schema|mysql|sys'
MANDAL_MARIADB_DROPIN="/etc/mysql/mariadb.conf.d/60-mandal.cnf"

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
    # Each stage may legitimately match nothing; under pipefail that must not abort the pipeline.
    databases="$({ "$client" -N -B -e 'SHOW DATABASES' 2> /dev/null || true; } | { grep -vE "^(${MANDAL_DB_SYSTEM_SCHEMAS})\$" || true; } | json_lines)"
    users="$({ "$client" -N -B -e "SELECT user, host FROM mysql.user WHERE user NOT IN ('root', 'mariadb.sys', 'mysql', 'debian-sys-maint', 'healthcheck') AND user <> ''" 2> /dev/null || true; } | json_tsv user host)"
    jq -n --argjson databases "$databases" --argjson users "$users" '{databases: $databases, users: $users}'
}

db_sql() {
    local client
    printf '+ sql: %s\n' "$1"
    dry_run && return 0
    client="$(db_client)" || die "no MariaDB client found"
    "$client" --batch -e "$1"
}

# Install MariaDB, bind it to localhost and apply the usual secure-installation steps.
db_install() {
    apt_install mariadb-server mariadb-client
    write_file "$MANDAL_MARIADB_DROPIN" 0644 <<'EOF'
# managed-by: mandal
[mysqld]
bind-address = 127.0.0.1
EOF
    service_enable_now mariadb
    service_restart mariadb
    db_secure
}

# The equivalent of mariadb-secure-installation, without the prompts.
db_secure() {
    db_sql "ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket"
    db_sql "DELETE FROM mysql.global_priv WHERE User=''"
    db_sql "DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1')"
    db_sql "DROP DATABASE IF EXISTS test"
    db_sql "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%'"
    db_sql "FLUSH PRIVILEGES"
}
