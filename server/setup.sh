#!/usr/bin/env bash
#
# Mandal server setup. Run as root on a fresh Ubuntu 26.04 server, with the
# command the panel shows under "Setup command":
#
#   curl -fsSL https://github.com/ReyArlena/mandal/releases/latest/download/setup.sh \
#       | sudo bash -s -- --key='ssh-ed25519 AAAA... mandal-panel'
#
# Creates the SSH user the panel connects as, installs the panel's public key
# and the mandal CLI from a tagged release of the repository, and lets that
# user run only the CLI through sudo. Nothing else on the server is changed;
# provisioning happens from the panel.
#
# Safe to run again: every step is skipped when it is already done, and a
# CLI that is newer than the requested release (the panel pushes its own
# build after it connects) is left alone unless --force is given.
#
# Options:
#   --key='<public key>'  The panel's public key (required)
#   --user=<name>         The SSH user the panel connects as (default: mandal)
#   --version=<tag>       Release to install the CLI from (default: the latest)
#   --repo=<owner/name>   GitHub repository to install from (default: ReyArlena/mandal)
#   --source=<dir>        Install the CLI from a local checkout instead of cloning
#   --force               Replace the installed CLI even when it is the same or newer
#   --dry-run             Print what would be done without changing anything

set -euo pipefail

MANDAL_HOME_DIR="${MANDAL_HOME_DIR:-/home}"
MANDAL_INSTALL_DIR="${MANDAL_INSTALL_DIR:-/opt/mandal}"
MANDAL_BIN_LINK="${MANDAL_BIN_LINK:-/usr/local/bin/mandal}"
MANDAL_SUDOERS_FILE="${MANDAL_SUDOERS_FILE:-/etc/sudoers.d/mandal}"

user="mandal"
public_key=""
version=""
repo="ReyArlena/mandal"
source_dir=""
force=false
dry_run=false

for arg in "$@"; do
    case "$arg" in
        --key=*) public_key="${arg#*=}" ;;
        --user=*) user="${arg#*=}" ;;
        --version=*) version="${arg#*=}" ;;
        --repo=*) repo="${arg#*=}" ;;
        --source=*) source_dir="${arg#*=}" ;;
        --force) force=true ;;
        --dry-run) dry_run=true ;;
        -h | --help)
            sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "error: unknown option '$arg'" >&2
            exit 64
            ;;
    esac
done

die() {
    echo "error: $*" >&2
    exit 1
}

step() {
    printf '\n==> %s\n' "$*"
}

# Run a command, echoing it first. Skipped (but still echoed) in dry-run mode.
run_cmd() {
    printf '+ %s\n' "$*"
    if [[ "$dry_run" == true ]]; then
        return 0
    fi
    "$@"
}

# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

[[ -n "$public_key" ]] || die "--key is required (the panel's public key, shown on its server page)"
[[ "$user" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "invalid user name '$user'"
[[ "$repo" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]] || die "invalid repository '$repo' (expected owner/name)"
[[ -z "$version" || "$version" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "invalid version '$version' (expected a release tag such as v0.1.0)"
[[ -z "$source_dir" || -x "$source_dir/server/bin/mandal" ]] || die "--source must point at a checkout of the repository (no server/bin/mandal in '$source_dir')"

key_type="$(awk '{print $1}' <<< "$public_key")"
key_blob="$(awk '{print $2}' <<< "$public_key")"
key_comment="$(awk '{$1 = ""; $2 = ""; sub(/^  /, ""); print}' <<< "$public_key")"
[[ "$key_type" =~ ^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com)$ ]] || die "unsupported key type '$key_type'"
[[ "$key_blob" =~ ^[A-Za-z0-9+/=]+$ && "$public_key" != *$'\n'* ]] || die "invalid public key"
printf '%s' "$key_blob" | base64 -d > /dev/null 2>&1 || die "invalid public key"
key_line="$key_type $key_blob ${key_comment:-mandal-panel}"

if [[ "$dry_run" == false && "$(id -u)" -ne 0 ]]; then
    die "run this script as root (sudo -i, then run it again)"
fi

home="$MANDAL_HOME_DIR/$user"
authorized_keys="$home/.ssh/authorized_keys"
clone_url="https://github.com/$repo.git"

# ---------------------------------------------------------------------------
# Tools the CLI needs before the server is provisioned
# ---------------------------------------------------------------------------

missing=()
for tool in jq git tar; do
    command -v "$tool" > /dev/null 2>&1 || missing+=("$tool")
done
if [[ ${#missing[@]} -gt 0 ]]; then
    step "Installing ${missing[*]}"
    export DEBIAN_FRONTEND=noninteractive
    run_cmd apt-get update -qq
    run_cmd apt-get install -y -qq "${missing[@]}"
fi

# ---------------------------------------------------------------------------
# The SSH user and the panel's key
# ---------------------------------------------------------------------------

step "SSH user $user"
if id "$user" > /dev/null 2>&1; then
    printf '+ user %s already exists\n' "$user"
else
    run_cmd useradd --create-home --home-dir "$home" --shell /bin/bash --user-group "$user"
fi

step "Panel key"
if [[ -f "$authorized_keys" ]] && grep -qF "$key_blob" "$authorized_keys"; then
    printf '+ key already allowed in %s\n' "$authorized_keys"
else
    run_cmd install -d -m 0700 -o "$user" -g "$user" "$home/.ssh"
    printf '+ append to %s: %s\n' "$authorized_keys" "$key_line"
    if [[ "$dry_run" == false ]]; then
        printf '%s\n' "$key_line" >> "$authorized_keys"
        chmod 0600 "$authorized_keys"
        chown "$user:$user" "$authorized_keys"
    fi
fi

# ---------------------------------------------------------------------------
# The CLI
# ---------------------------------------------------------------------------

installed_version=""
if [[ -r "$MANDAL_INSTALL_DIR/VERSION" ]]; then
    installed_version="$(tr -d '[:space:]' < "$MANDAL_INSTALL_DIR/VERSION")"
fi

# Resolve what to install: a local checkout, the requested tag, or the
# newest release tag the repository has.
if [[ -n "$source_dir" ]]; then
    target_version="$(tr -d '[:space:]' < "$source_dir/VERSION" 2> /dev/null || echo unknown)"
    target_label="$source_dir (${target_version})"
else
    if [[ -z "$version" ]]; then
        version="$(git ls-remote --tags --refs "$clone_url" 'refs/tags/v*' 2> /dev/null | awk -F/ '{print $NF}' | sort -V | tail -n 1 || true)"
        [[ -n "$version" ]] || die "no release tags found in $clone_url; pass --version=<tag> or --source=<checkout>"
    fi
    [[ "$version" == v* ]] || version="v$version"
    target_version="${version#v}"
    target_label="$repo@$version"
fi

# Highest of two versions, as sort -V sees them.
version_at_least() {
    [[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" == "$1" ]]
}

step "mandal CLI from $target_label"
if [[ "$force" == false && -n "$installed_version" && "$target_version" != unknown ]] && version_at_least "$installed_version" "$target_version"; then
    printf '+ mandal %s already installed in %s (use --force to replace it)\n' "$installed_version" "$MANDAL_INSTALL_DIR"
else
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    if [[ -n "$source_dir" ]]; then
        checkout="$source_dir"
    else
        checkout="$tmp/checkout"
        run_cmd git clone --quiet --depth 1 --branch "$version" "$clone_url" "$checkout"
    fi
    if [[ "$dry_run" == false ]]; then
        [[ -x "$checkout/server/bin/mandal" && -f "$checkout/server/lib/core.sh" ]] || die "$target_label does not contain the mandal CLI"
        bash -n "$checkout/server/bin/mandal" || die "the CLI in $target_label does not parse"
        staged="$tmp/mandal"
        mkdir -p "$staged"
        cp -R "$checkout/server/." "$staged/"
        rm -rf "$staged/tests"
        cp "$checkout/VERSION" "$staged/VERSION"
        chown -R root:root "$staged"
        chmod -R go-w "$staged"
        chmod 0755 "$staged" "$staged/bin/mandal"
    fi
    printf '+ install %s -> %s\n' "$target_label" "$MANDAL_INSTALL_DIR"
    if [[ "$dry_run" == false ]]; then
        rm -rf "${MANDAL_INSTALL_DIR}.old"
        mkdir -p "$(dirname "$MANDAL_INSTALL_DIR")"
        [[ ! -e "$MANDAL_INSTALL_DIR" ]] || mv "$MANDAL_INSTALL_DIR" "${MANDAL_INSTALL_DIR}.old"
        mv "$staged" "$MANDAL_INSTALL_DIR"
        rm -rf "${MANDAL_INSTALL_DIR}.old"
    fi
    run_cmd ln -sfn "$MANDAL_INSTALL_DIR/bin/mandal" "$MANDAL_BIN_LINK"
fi

# ---------------------------------------------------------------------------
# sudo: only the CLI, as root, without a password
# ---------------------------------------------------------------------------

step "sudo rule for $user"
sudoers_line="$user ALL=(root) NOPASSWD: $MANDAL_INSTALL_DIR/bin/mandal"
if [[ -f "$MANDAL_SUDOERS_FILE" ]] && [[ "$(cat "$MANDAL_SUDOERS_FILE")" == "$sudoers_line" ]]; then
    printf '+ %s unchanged\n' "$MANDAL_SUDOERS_FILE"
else
    printf '+ write %s: %s\n' "$MANDAL_SUDOERS_FILE" "$sudoers_line"
    if [[ "$dry_run" == false ]]; then
        mkdir -p "$(dirname "$MANDAL_SUDOERS_FILE")"
        printf '%s\n' "$sudoers_line" > "$MANDAL_SUDOERS_FILE.tmp"
        chmod 0440 "$MANDAL_SUDOERS_FILE.tmp"
        visudo -cf "$MANDAL_SUDOERS_FILE.tmp" > /dev/null
        mv -f "$MANDAL_SUDOERS_FILE.tmp" "$MANDAL_SUDOERS_FILE"
    fi
fi

echo
if [[ "$dry_run" == true ]]; then
    echo "Dry run finished; nothing was changed."
else
    echo "Done. mandal $(tr -d '[:space:]' < "$MANDAL_INSTALL_DIR/VERSION") is installed and the panel can now connect as $user."
    echo "Return to the panel and use 'Test connection'."
fi
