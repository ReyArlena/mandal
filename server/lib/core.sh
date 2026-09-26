#!/usr/bin/env bash
#
# Shared helpers for the mandal CLI: paths, output, config files, locking,
# validation, role drivers and command dispatch.
#
# Every path can be overridden through the environment so the tool can be
# exercised against fixture directories without touching a real system.

MANDAL_ETC="${MANDAL_ETC:-/etc/mandal}"
MANDAL_LOG_DIR="${MANDAL_LOG_DIR:-/var/log/mandal}"
MANDAL_LOCK_FILE="${MANDAL_LOCK_FILE:-/run/lock/mandal.lock}"
MANDAL_HOME_DIR="${MANDAL_HOME_DIR:-/home}"
MANDAL_NGINX_DIR="${MANDAL_NGINX_DIR:-/etc/nginx}"
MANDAL_PHP_DIR="${MANDAL_PHP_DIR:-/etc/php}"
MANDAL_SUPERVISOR_DIR="${MANDAL_SUPERVISOR_DIR:-/etc/supervisor/conf.d}"
MANDAL_DOCKER_DIR="${MANDAL_DOCKER_DIR:-/srv/docker}"
MANDAL_OS_RELEASE="${MANDAL_OS_RELEASE:-/etc/os-release}"
MANDAL_REBOOT_REQUIRED="${MANDAL_REBOOT_REQUIRED:-/var/run/reboot-required}"
MANDAL_SSHD_DROPIN_DIR="${MANDAL_SSHD_DROPIN_DIR:-/etc/ssh/sshd_config.d}"

MANDAL_SERVER_CONF="${MANDAL_ETC}/server.conf"
MANDAL_APPS_DIR="${MANDAL_ETC}/apps"
MANDAL_ACTIONS_LOG="${MANDAL_LOG_DIR}/actions.log"

MANDAL_NAME_PATTERN='^[a-z][a-z0-9-]{1,31}$'
MANDAL_DOMAIN_PATTERN='^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'
MANDAL_PHP_VERSION_PATTERN='^[0-9]+\.[0-9]+$'
MANDAL_RESERVED_NAMES=(mandal root admin www-data nginx mysql mariadb redis dragonfly docker daemon sys sync games man lp mail news uucp proxy backup list irc nobody ubuntu)

# Roles and the driver used for each when server.conf does not say otherwise.
declare -A MANDAL_DEFAULT_DRIVERS=(
    [database]=mariadb
    [cache]=dragonfly
    [js]=bun
)

# Populated by command files: "name|summary|read-or-write".
MANDAL_COMMANDS=()

# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------

mandal_version() {
    local file
    for file in "${MANDAL_ROOT}/VERSION" "${MANDAL_ROOT}/../VERSION"; do
        if [[ -r "$file" ]]; then
            printf '%s\n' "$(tr -d '[:space:]' < "$file")"
            return 0
        fi
    done
    echo "unknown"
}

log() {
    printf '%s\n' "$*"
}

warn() {
    printf 'warning: %s\n' "$*" >&2
}

# die [exit-code] message
die() {
    local code=1
    if [[ $# -gt 1 && "$1" =~ ^[0-9]+$ ]]; then
        code="$1"
        shift
    fi
    printf 'error: %s\n' "$*" >&2
    exit "$code"
}

has_cmd() {
    command -v "$1" > /dev/null 2>&1
}

is_root() {
    [[ "${EUID:-$(id -u)}" -eq 0 ]]
}

require_root() {
    is_root || die 77 "this command must be run as root (use sudo)"
}

# Convert newline-separated values on stdin into a JSON array of strings.
json_lines() {
    jq -R -s 'split("\n") | map(select(length > 0))'
}

# Convert tab-separated lines on stdin into a JSON array of objects.
# Usage: json_tsv field1 field2 ...
json_tsv() {
    local fields
    fields="$(printf '%s\n' "$@" | jq -R -s 'split("\n") | map(select(length > 0))')"
    jq -R -s --argjson fields "$fields" '
        split("\n")
        | map(select(length > 0) | split("\t"))
        | map(. as $row | $fields | to_entries | map({key: .value, value: ($row[.key] // "")}) | from_entries)
    '
}

json_bool() {
    if [[ "$1" == "true" || "$1" == "0" || "$1" == "yes" ]]; then
        echo true
    else
        echo false
    fi
}

# ---------------------------------------------------------------------------
# Config files (KEY=value, read line by line, never sourced)
# ---------------------------------------------------------------------------

conf_get() {
    local file="$1" key="$2" line value=""
    [[ -r "$file" ]] || return 1
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" == "${key}="* ]] || continue
        value="${line#*=}"
        value="${value%\"}"
        value="${value#\"}"
    done < "$file"
    [[ -n "$value" ]] || return 1
    printf '%s\n' "$value"
}

# Print the whole file as a JSON object with lower-case keys.
conf_read() {
    local file="$1"
    if [[ ! -r "$file" ]]; then
        echo '{}'
        return 0
    fi
    grep -E '^[A-Z][A-Z0-9_]*=' "$file" 2> /dev/null | jq -R -s '
        split("\n")
        | map(select(length > 0))
        | map(capture("^(?<key>[A-Z][A-Z0-9_]*)=(?<value>.*)$"))
        | map({key: (.key | ascii_downcase), value: (.value | ltrimstr("\"") | rtrimstr("\""))})
        | from_entries
    ' || echo '{}'
}

# conf_set file KEY value - replaces the key or appends it, writing atomically.
conf_set() {
    local file="$1" key="$2" value="$3" tmp
    [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || die "invalid config key: $key"
    [[ "$value" != *$'\n'* ]] || die "config values may not contain newlines"
    mkdir -p "$(dirname "$file")"
    tmp="$(mktemp "${file}.XXXXXX")"
    if [[ -f "$file" ]]; then
        grep -v -E "^${key}=" "$file" > "$tmp" || true
    fi
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
    chmod --reference="$file" "$tmp" 2> /dev/null || chmod 0644 "$tmp"
    mv -f "$tmp" "$file"
}

server_conf_get() {
    conf_get "$MANDAL_SERVER_CONF" "$1"
}

app_conf_path() {
    printf '%s/%s.conf\n' "$MANDAL_APPS_DIR" "$1"
}

app_exists() {
    [[ -f "$(app_conf_path "$1")" ]]
}

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------

validate_name() {
    local name="$1" reserved
    [[ "$name" =~ $MANDAL_NAME_PATTERN ]] || die 2 "invalid name '$name': use 2-32 lower-case letters, digits or hyphens, starting with a letter"
    for reserved in "${MANDAL_RESERVED_NAMES[@]}"; do
        [[ "$name" != "$reserved" ]] || die 2 "'$name' is a reserved name"
    done
}

validate_domain() {
    local domain="$1"
    [[ ${#domain} -le 253 ]] || die 2 "domain '$domain' is too long"
    [[ "$domain" =~ $MANDAL_DOMAIN_PATTERN ]] || die 2 "invalid domain '$domain'"
}

validate_php_version() {
    [[ "$1" =~ $MANDAL_PHP_VERSION_PATTERN ]] || die 2 "invalid PHP version '$1' (expected e.g. 8.4)"
}

# ---------------------------------------------------------------------------
# Locking and audit log (write commands)
# ---------------------------------------------------------------------------

# Take the global write lock for the rest of the process.
with_lock() {
    mkdir -p "$(dirname "$MANDAL_LOCK_FILE")"
    exec 9> "$MANDAL_LOCK_FILE"
    if ! flock -w "${MANDAL_LOCK_WAIT:-30}" 9; then
        die 75 "another mandal command is running; try again shortly"
    fi
}

audit() {
    local actor="${SUDO_USER:-${USER:-unknown}}" panel="${MANDAL_PANEL:-cli}"
    mkdir -p "$MANDAL_LOG_DIR"
    printf '%s\t%s\t%s\t%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$actor" "$panel" "$*" >> "$MANDAL_ACTIONS_LOG"
}

# ---------------------------------------------------------------------------
# Role drivers
# ---------------------------------------------------------------------------

driver_for() {
    local role="$1" driver
    driver="$(server_conf_get "$(printf '%s' "$role" | tr '[:lower:]' '[:upper:]')" 2> /dev/null || true)"
    printf '%s\n' "${driver:-${MANDAL_DEFAULT_DRIVERS[$role]:-}}"
}

load_driver() {
    local role="$1" driver file
    driver="$(driver_for "$role")"
    [[ -n "$driver" ]] || die "no driver configured for role '$role'"
    file="${MANDAL_ROOT}/lib/roles/${role}/${driver}.sh"
    [[ -r "$file" ]] || die "unknown ${role} driver '${driver}'"
    # shellcheck disable=SC1090
    source "$file"
}

load_all_drivers() {
    local role
    for role in "${!MANDAL_DEFAULT_DRIVERS[@]}"; do
        load_driver "$role"
    done
}

# ---------------------------------------------------------------------------
# Services and packages
# ---------------------------------------------------------------------------

unit_state() {
    if has_cmd systemctl; then
        systemctl is-active "$1" 2> /dev/null || true
    else
        echo "unknown"
    fi
}

unit_exists() {
    has_cmd systemctl || return 1
    [[ "$(systemctl show "$1" -p LoadState --value 2> /dev/null)" == "loaded" ]]
}

package_installed() {
    has_cmd dpkg-query || return 1
    dpkg-query -W -f='${Status}' "$1" 2> /dev/null | grep -q 'install ok installed'
}

package_version() {
    has_cmd dpkg-query || return 0
    dpkg-query -W -f='${Version}' "$1" 2> /dev/null || true
}

# ---------------------------------------------------------------------------
# Commands
# ---------------------------------------------------------------------------

mandal_load_commands() {
    local file
    for file in "${MANDAL_ROOT}"/lib/system.sh "${MANDAL_ROOT}"/lib/php.sh "${MANDAL_ROOT}"/lib/provision.sh "${MANDAL_ROOT}"/lib/app.sh "${MANDAL_ROOT}"/lib/deploy.sh "${MANDAL_ROOT}"/lib/commands/*.sh; do
        # shellcheck disable=SC1090
        source "$file"
    done
}

mandal_usage() {
    local entry name summary
    printf 'mandal %s\n\n' "$(mandal_version)"
    printf 'Usage: mandal <command> [options]\n\nCommands:\n'
    for entry in "${MANDAL_COMMANDS[@]}"; do
        IFS='|' read -r name summary _ <<< "$entry"
        printf '  %-24s %s\n' "$name" "$summary"
    done | sort
}

mandal_dispatch() {
    local command="${1:-help}" fn
    shift || true
    case "$command" in
        help | --help | -h)
            mandal_usage
            return 0
            ;;
        --version | -v)
            mandal_version
            return 0
            ;;
    esac
    fn="cmd_${command//:/_}"
    fn="${fn//-/_}"
    if ! declare -F "$fn" > /dev/null; then
        printf 'error: unknown command "%s"\n\n' "$command" >&2
        mandal_usage >&2
        exit 64
    fi
    "$fn" "$@"
}
