#!/usr/bin/env bash
#
# Mandal panel installer and updater. Run as root on a fresh Ubuntu 26.04
# server to host the panel on it:
#
#   curl -fsSL https://github.com/ReyArlena/mandal/releases/latest/download/install.sh \
#       | sudo bash -s -- --domain=panel.example.com
#
# Installs the mandal CLI from a tagged release, provisions the server,
# creates the panel as a protected app, deploys it, requests a certificate,
# creates the first user and registers the server in the panel so it
# manages itself. Every step is skipped when it is already done, so the
# script can be run again to resume after an interruption.
#
# Run again without --domain on a server that already hosts the panel to
# update it: the CLI is updated first, then the panel is deployed from the
# newer release.
#
# Options:
#   --domain=<name>       The panel's domain (required the first time)
#   --version=<tag>       Release to install (default: the latest)
#   --repo=<owner/name>   GitHub repository to install from (default: ReyArlena/mandal)
#   --name=<app>          Name of the panel app (default: mandal-panel)
#   --email=<address>     Contact address for Let's Encrypt
#   --skip-ssl            Do not request a certificate
#   --source=<dir>        Install from a local checkout instead of cloning
#
# Provisioning uses the default drivers; run `mandal provision` by hand first
# to choose others. The script skips provisioning when it has been done.

set -euo pipefail

MANDAL_ETC="${MANDAL_ETC:-/etc/mandal}"
MANDAL_HOME_DIR="${MANDAL_HOME_DIR:-/home}"
MANDAL_INSTALL_DIR="${MANDAL_INSTALL_DIR:-/opt/mandal}"
MANDAL_BIN_LINK="${MANDAL_BIN_LINK:-/usr/local/bin/mandal}"
MANDAL_LETSENCRYPT_DIR="${MANDAL_LETSENCRYPT_DIR:-/etc/letsencrypt/live}"
MANDAL_HOST_KEY_FILE="${MANDAL_HOST_KEY_FILE:-/etc/ssh/ssh_host_ed25519_key.pub}"
MANDAL_PHP_BIN_DIR="${MANDAL_PHP_BIN_DIR:-/usr/bin}"
MANDAL_BIN="${MANDAL_BIN:-$MANDAL_INSTALL_DIR/bin/mandal}"

domain=""
version=""
repo="ReyArlena/mandal"
name="mandal-panel"
email=""
skip_ssl=false
source_dir=""

for arg in "$@"; do
    case "$arg" in
        --domain=*) domain="${arg#*=}" ;;
        --version=*) version="${arg#*=}" ;;
        --repo=*) repo="${arg#*=}" ;;
        --name=*) name="${arg#*=}" ;;
        --email=*) email="${arg#*=}" ;;
        --skip-ssl) skip_ssl=true ;;
        --source=*) source_dir="${arg#*=}" ;;
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

skip() {
    printf '+ %s\n' "$*"
}

run_cmd() {
    printf '+ %s\n' "$*"
    "$@"
}

mandal() {
    "$MANDAL_BIN" "$@"
}

# conf_get file KEY, as the CLI reads its KEY=value files.
conf_get() {
    local value
    value="$(grep -E "^$2=" "$1" 2> /dev/null | tail -n 1 | cut -d= -f2- || true)"
    value="${value%\"}"
    printf '%s\n' "${value#\"}"
}

# Highest of two versions, as sort -V sees them.
version_at_least() {
    [[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" == "$1" ]]
}

# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

[[ "$(id -u)" -eq 0 ]] || die "run this script as root (sudo -i, then run it again)"
[[ "$repo" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]] || die "invalid repository '$repo' (expected owner/name)"
[[ "$name" =~ ^[a-z][a-z0-9-]{1,31}$ ]] || die "invalid app name '$name'"
[[ -z "$version" || "$version" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "invalid version '$version' (expected a release tag such as v0.1.0)"
[[ -z "$source_dir" || -x "$source_dir/server/bin/mandal" ]] || die "--source must point at a checkout of the repository (no server/bin/mandal in '$source_dir')"

manifest="$MANDAL_ETC/apps/$name.conf"
home="$MANDAL_HOME_DIR/$name"
if [[ -f "$manifest" ]]; then
    [[ "$(conf_get "$manifest" PANEL)" == "true" ]] || die "app '$name' exists but is not the panel"
    [[ -z "$domain" || "$domain" == "$(conf_get "$manifest" DOMAIN)" ]] || die "the panel is installed for $(conf_get "$manifest" DOMAIN); it cannot be moved to $domain here"
    domain="$(conf_get "$manifest" DOMAIN)"
fi
[[ -n "$domain" ]] || die "--domain is required the first time"
[[ "$domain" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || die "invalid domain '$domain'"

clone_url="https://github.com/$repo.git"

# ---------------------------------------------------------------------------
# Tools needed before the server is provisioned
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
# The release to install
# ---------------------------------------------------------------------------

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

if [[ -n "$source_dir" ]]; then
    checkout="$source_dir"
    target_version="$(tr -d '[:space:]' < "$checkout/VERSION")"
    version="v$target_version"
    target_label="$source_dir ($target_version)"
else
    if [[ -z "$version" ]]; then
        version="$(git ls-remote --tags --refs "$clone_url" 'refs/tags/v*' 2> /dev/null | awk -F/ '{print $NF}' | sort -V | tail -n 1 || true)"
        [[ -n "$version" ]] || die "no release tags found in $clone_url; pass --version=<tag> or --source=<checkout>"
    fi
    [[ "$version" == v* ]] || version="v$version"
    target_version="${version#v}"
    target_label="$repo@$version"
    checkout="$tmp/checkout"
    step "Fetching $target_label"
    run_cmd git clone --quiet --depth 1 --branch "$version" "$clone_url" "$checkout"
    [[ "$(tr -d '[:space:]' < "$checkout/VERSION")" == "$target_version" ]] || die "$version has VERSION $(tr -d '[:space:]' < "$checkout/VERSION"); tags must match the VERSION file"
fi
[[ -x "$checkout/server/bin/mandal" && -f "$checkout/server/lib/core.sh" ]] || die "$target_label does not contain the mandal CLI"

# ---------------------------------------------------------------------------
# The CLI: installed fresh, updated through itself, or left alone
# ---------------------------------------------------------------------------

installed_version=""
if [[ -r "$MANDAL_INSTALL_DIR/VERSION" ]]; then
    installed_version="$(tr -d '[:space:]' < "$MANDAL_INSTALL_DIR/VERSION")"
fi

stage_cli() {
    local staged="$1"
    mkdir -p "$staged"
    cp -R "$checkout/server/." "$staged/"
    rm -rf "$staged/tests"
    cp "$checkout/VERSION" "$staged/VERSION"
    chown -R root:root "$staged"
    chmod -R go-w "$staged"
    chmod 0755 "$staged" "$staged/bin/mandal"
}

if [[ -n "$installed_version" && -f "$manifest" ]]; then
    # An installed panel updates itself through the CLI; this is the same
    # as running `sudo mandal panel:update` by hand.
    step "Updating the panel to $target_label"
    update_args=(--version="$version")
    [[ -z "$source_dir" ]] || update_args+=(--source="$source_dir")
    run_cmd mandal panel:update "${update_args[@]}"
else
    step "mandal CLI $target_version"
    if [[ -z "$installed_version" ]]; then
        stage_cli "$tmp/mandal"
        printf '+ install %s -> %s\n' "$target_label" "$MANDAL_INSTALL_DIR"
        mkdir -p "$(dirname "$MANDAL_INSTALL_DIR")"
        mv "$tmp/mandal" "$MANDAL_INSTALL_DIR"
        run_cmd ln -sfn "$MANDAL_INSTALL_DIR/bin/mandal" "$MANDAL_BIN_LINK"
    elif version_at_least "$installed_version" "$target_version"; then
        skip "mandal $installed_version already installed"
    else
        stage_cli "$tmp/mandal"
        tar -C "$tmp/mandal" -czf "$tmp/mandal-cli.tar.gz" .
        run_cmd mandal cli:update --from="$tmp/mandal-cli.tar.gz"
    fi

    step "Provisioning"
    if [[ -n "$(conf_get "$MANDAL_ETC/server.conf" PROVISIONED_AT)" ]]; then
        skip "already provisioned on $(conf_get "$MANDAL_ETC/server.conf" PROVISIONED_AT)"
    else
        run_cmd mandal provision
    fi

    step "Panel app $name for $domain"
    run_cmd mandal panel:install --name="$name" --domain="$domain" --repo="$repo" --branch="$version" --https

    step "Deploying $target_label"
    run_cmd mandal app:deploy --name="$name"
fi

php="$MANDAL_PHP_BIN_DIR/php$(conf_get "$manifest" PHP)"
artisan() {
    sudo -u "$name" -H "$php" "$home/current/artisan" "$@"
}

step "Certificate"
if [[ "$skip_ssl" == true ]]; then
    skip "skipped (--skip-ssl)"
elif [[ -f "$MANDAL_LETSENCRYPT_DIR/$domain/fullchain.pem" ]]; then
    skip "certificate for $domain already present"
else
    ssl_args=(--name="$name")
    [[ -z "$email" ]] || ssl_args+=(--email="$email")
    if ! run_cmd mandal app:ssl "${ssl_args[@]}"; then
        printf 'warning: no certificate yet. Point %s at this server and run this script again.\n' "$domain" >&2
    fi
fi

step "First user"
run_cmd artisan db:seed --force --no-interaction > /dev/null

# ---------------------------------------------------------------------------
# Let the panel manage this server
# ---------------------------------------------------------------------------

step "Registering this server in the panel"
registration="$(artisan mandal:register-host --host-key-file="$MANDAL_HOST_KEY_FILE" --no-interaction)"
panel_key="$(jq -r '.public_key' <<< "$registration")"
[[ -n "$panel_key" && "$panel_key" != null ]] || die "the panel did not return its public key: $registration"
run_cmd mandal key:add --name="Hosted panel" --key="$panel_key"
if [[ "$(jq -r '.connected' <<< "$registration")" == "true" ]]; then
    skip "already connected"
else
    run_cmd artisan mandal:register-host --host-key-file="$MANDAL_HOST_KEY_FILE" --verify --no-interaction > /dev/null
fi

cat <<EOF

Mandal $target_version is installed at https://$domain

Sign in with admin@admin.com / changethispassword, set up the authenticator
app it asks for, then change the email address and password from your
profile. The server appears in the panel as its own first server.

Run this script again at any time to update to the latest release.
EOF
