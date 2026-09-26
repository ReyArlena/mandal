#!/usr/bin/env bash
#
# Helpers for commands that change the server: running commands, writing
# files, apt, services. Everything here honours MANDAL_DRY_RUN=1, which
# prints what would happen instead of doing it, so the provisioning
# sequence can be exercised without a server.

MANDAL_CONFIG_BACKUP_DIR="${MANDAL_CONFIG_BACKUP_DIR:-/var/backups/mandal/config}"
MANDAL_PROC_SWAPS="${MANDAL_PROC_SWAPS:-/proc/swaps}"
MANDAL_APT_SOURCES_DIR="${MANDAL_APT_SOURCES_DIR:-/etc/apt/sources.list.d}"

export DEBIAN_FRONTEND=noninteractive

dry_run() {
    [[ "${MANDAL_DRY_RUN:-0}" == "1" ]]
}

step() {
    printf '\n==> %s\n' "$*"
}

# Run a command, echoing it first. Skipped (but still echoed) in dry-run mode.
run_cmd() {
    printf '+ %s\n' "$*"
    if dry_run; then
        return 0
    fi
    "$@"
}

# Copy an existing file into the config backup directory before changing it.
backup_file() {
    local file="$1" dest
    [[ -f "$file" ]] || return 0
    dest="${MANDAL_CONFIG_BACKUP_DIR}/$(date -u +%Y%m%dT%H%M%SZ)${file}"
    printf '+ backup %s -> %s\n' "$file" "$dest"
    dry_run && return 0
    mkdir -p "$(dirname "$dest")"
    cp -p "$file" "$dest"
}

# write_file path mode [owner:group] < content
# Writes atomically, backing up any existing different file first.
# Returns 0 and prints "unchanged" when the content is already in place.
write_file() {
    local file="$1" mode="$2" owner="${3:-root:root}" content tmp
    content="$(cat)"
    if [[ -f "$file" ]] && [[ "$(cat "$file")" == "$content" ]]; then
        printf '+ %s unchanged\n' "$file"
        return 0
    fi
    printf '+ write %s (%s %s)\n' "$file" "$mode" "$owner"
    dry_run && return 0
    backup_file "$file"
    mkdir -p "$(dirname "$file")"
    tmp="$(mktemp "${file}.XXXXXX")"
    printf '%s\n' "$content" > "$tmp"
    chmod "$mode" "$tmp"
    if [[ "$(id -u)" -eq 0 ]]; then
        chown "$owner" "$tmp"
    fi
    mv -f "$tmp" "$file"
}

# Append a line to a file only if an identical line is not already there.
ensure_line() {
    local file="$1" line="$2"
    if [[ -f "$file" ]] && grep -qxF -- "$line" "$file"; then
        return 0
    fi
    printf '+ append to %s: %s\n' "$file" "$line"
    dry_run && return 0
    backup_file "$file"
    mkdir -p "$(dirname "$file")"
    printf '%s\n' "$line" >> "$file"
}

# ---------------------------------------------------------------------------
# apt
# ---------------------------------------------------------------------------

APT_OPTIONS=(-y -q -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef -o DPkg::Lock::Timeout=300)
APT_UPDATED=0

apt_update() {
    [[ "$APT_UPDATED" == 1 && "${1:-}" != "--force" ]] && return 0
    run_cmd apt-get update -q
    APT_UPDATED=1
}

# Install packages that are not yet installed.
apt_install() {
    local missing=() package
    for package in "$@"; do
        package_installed "$package" || missing+=("$package")
    done
    if [[ ${#missing[@]} -eq 0 ]]; then
        printf '+ already installed: %s\n' "$*"
        return 0
    fi
    apt_update
    run_cmd apt-get install "${APT_OPTIONS[@]}" "${missing[@]}"
}

apt_package_exists() {
    apt-cache show "$1" > /dev/null 2>&1
}

# Add a deb822 apt source with its key, only if not already present.
# apt_add_source name keyring-url repo-url suite components
apt_add_source() {
    local name="$1" key_url="$2" repo="$3" suite="$4" components="$5"
    local keyring="/etc/apt/keyrings/${name}.asc" source="${MANDAL_APT_SOURCES_DIR}/${name}.sources"
    if [[ -f "$source" ]]; then
        printf '+ apt source %s already present\n' "$name"
        return 0
    fi
    run_cmd install -m 0755 -d /etc/apt/keyrings
    printf '+ download %s -> %s\n' "$key_url" "$keyring"
    if ! dry_run; then
        curl -fsSL "$key_url" -o "$keyring"
        chmod a+r "$keyring"
    fi
    write_file "$source" 0644 <<EOF
Types: deb
URIs: ${repo}
Suites: ${suite}
Components: ${components}
Architectures: $(dpkg --print-architecture 2> /dev/null || echo amd64)
Signed-By: ${keyring}
EOF
    apt_update --force
}

# ---------------------------------------------------------------------------
# systemd
# ---------------------------------------------------------------------------

service_enable_now() {
    run_cmd systemctl enable --now --quiet "$1"
}

service_reload_or_restart() {
    run_cmd systemctl reload-or-restart "$1"
}

service_restart() {
    run_cmd systemctl restart "$1"
}

# ---------------------------------------------------------------------------
# Misc
# ---------------------------------------------------------------------------

swap_present() {
    [[ -r "$MANDAL_PROC_SWAPS" ]] && [[ "$(wc -l < "$MANDAL_PROC_SWAPS")" -gt 1 ]]
}

os_codename() {
    conf_get "$MANDAL_OS_RELEASE" VERSION_CODENAME 2> /dev/null || conf_get "$MANDAL_OS_RELEASE" UBUNTU_CODENAME 2> /dev/null || echo noble
}

cpu_arch() {
    uname -m 2> /dev/null || echo x86_64
}

# A random password safe for config files and shells.
random_secret() {
    local length="${1:-32}"
    LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$length"
}

# Download to a temp file; the path is left in DOWNLOADED_FILE. Fails loudly.
# (Not printed, so callers' progress output keeps streaming to stdout.)
download() {
    local url="$1"
    DOWNLOADED_FILE="$(mktemp)"
    printf '+ download %s\n' "$url"
    dry_run && return 0
    curl -fsSL --retry 3 --retry-delay 2 -o "$DOWNLOADED_FILE" "$url" || die "download failed: $url"
}
