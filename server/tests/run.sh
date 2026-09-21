#!/usr/bin/env bash
#
# Tests for the mandal CLI. They run on any machine with bash and jq: all
# system paths point at the fixtures directory and the tools mandal shells
# out to are replaced by small scripts in fixtures/bin.
#
#   server/tests/run.sh

# shellcheck disable=SC2015
set -uo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FIXTURES="${TESTS_DIR}/fixtures"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

MANDAL_ROOT="$(cd "${TESTS_DIR}/.." && pwd)"
export MANDAL_ROOT
export MANDAL_ETC="${TMP}/etc/mandal"
export MANDAL_LOG_DIR="${TMP}/log"
export MANDAL_LOCK_FILE="${TMP}/mandal.lock"
export MANDAL_HOME_DIR="${FIXTURES}/home"
export MANDAL_NGINX_DIR="${FIXTURES}/nginx"
export MANDAL_PHP_DIR="${FIXTURES}/php"
export MANDAL_SUPERVISOR_DIR="${FIXTURES}/supervisor"
export MANDAL_OS_RELEASE="${FIXTURES}/os-release"
export MANDAL_REBOOT_REQUIRED="${FIXTURES}/reboot-required"
export MANDAL_SSHD_DROPIN_DIR="${FIXTURES}/sshd_config.d"
export MANDAL_PROC_SWAPS="${FIXTURES}/swaps-none"
export MANDAL_APT_SOURCES_DIR="${TMP}/apt-sources"
export MANDAL_CONFIG_BACKUP_DIR="${TMP}/config-backups"
export MANDAL_DRAGONFLY_CONF="${TMP}/dragonfly.conf"
mkdir -p "${MANDAL_APT_SOURCES_DIR}"

# Only the fakes and a fixed set of real tools are on PATH, so whatever the
# host has installed (bun, docker, nginx...) cannot leak into the results.
mkdir -p "${TMP}/bin"
for tool in bash sh jq awk grep sed tr cut find wc basename dirname readlink id hostname uname nproc df mktemp mv chmod chown cat date sort uniq head tail tee ls touch flock printf mkdir cp rm ln env; do
    if path="$(command -v "$tool" 2> /dev/null)"; then
        ln -s "$path" "${TMP}/bin/${tool}"
    fi
done
export PATH="${FIXTURES}/bin:${TMP}/bin"

mkdir -p "${MANDAL_ETC}/apps"
cp "${FIXTURES}/etc/server.conf" "${MANDAL_ETC}/server.conf"
cp "${FIXTURES}/etc/apps/"*.conf "${MANDAL_ETC}/apps/"

# shellcheck source-path=SCRIPTDIR/../lib
# shellcheck source=core.sh
source "${MANDAL_ROOT}/lib/core.sh"
mandal_load_commands
load_all_drivers

# Collectors gate privileged reads on is_root; the fakes make them safe here.
is_root() { return 0; }

PASSED=0
FAILED=0

pass() { PASSED=$((PASSED + 1)); }
fail() {
    FAILED=$((FAILED + 1))
    printf 'FAIL: %s\n' "$1"
    [[ $# -gt 1 ]] && printf '      %s\n' "${@:2}"
}

# assert_eq description expected actual
assert_eq() {
    if [[ "$2" == "$3" ]]; then pass; else fail "$1" "expected: $2" "actual:   $3"; fi
}

# assert_json description json jq-filter expected
assert_json() {
    local actual
    actual="$(jq -r "$3" <<< "$2" 2>&1)"
    assert_eq "$1" "$4" "$actual"
}

# ---------------------------------------------------------------------------
# core: config files
# ---------------------------------------------------------------------------

conf="${TMP}/test.conf"
printf 'TYPE=laravel\nDOMAIN="example.com"\n# comment\nPHP=8.4\nPHP=8.5\n' > "$conf"

assert_eq "conf_get reads a value" "laravel" "$(conf_get "$conf" TYPE)"
assert_eq "conf_get strips quotes" "example.com" "$(conf_get "$conf" DOMAIN)"
assert_eq "conf_get takes the last duplicate" "8.5" "$(conf_get "$conf" PHP)"
conf_get "$conf" MISSING > /dev/null 2>&1 && fail "conf_get fails on a missing key" || pass
assert_json "conf_read lower-cases keys" "$(conf_read "$conf")" '.domain' "example.com"
assert_json "conf_read ignores comments" "$(conf_read "$conf")" 'keys | length' "3"
assert_eq "conf_read of a missing file is an empty object" '{}' "$(conf_read "${TMP}/nope.conf")"

conf_set "$conf" PHP 8.3
assert_eq "conf_set replaces a key" "8.3" "$(conf_get "$conf" PHP)"
assert_eq "conf_set leaves one copy of the key" "1" "$(grep -c '^PHP=' "$conf")"
conf_set "$conf" BRANCH main
assert_eq "conf_set appends a new key" "main" "$(conf_get "$conf" BRANCH)"
conf_set "${TMP}/new/dir/file.conf" A b
assert_eq "conf_set creates the file and directory" "b" "$(conf_get "${TMP}/new/dir/file.conf" A)"
(conf_set "$conf" 'bad key' x) > /dev/null 2>&1 && fail "conf_set rejects an invalid key" || pass

# ---------------------------------------------------------------------------
# core: validation
# ---------------------------------------------------------------------------

(validate_name myapp) && pass || fail "validate_name accepts a plain name"
(validate_name my-app-2) && pass || fail "validate_name accepts hyphens and digits"
(validate_name Myapp) 2> /dev/null && fail "validate_name rejects upper case" || pass
(validate_name 1app) 2> /dev/null && fail "validate_name rejects a leading digit" || pass
(validate_name a) 2> /dev/null && fail "validate_name rejects a single character" || pass
(validate_name root) 2> /dev/null && fail "validate_name rejects reserved names" || pass
(validate_name mandal) 2> /dev/null && fail "validate_name rejects mandal" || pass
(validate_name 'app;rm') 2> /dev/null && fail "validate_name rejects shell characters" || pass
(validate_domain example.com) && pass || fail "validate_domain accepts a domain"
(validate_domain sub.example.co.uk) && pass || fail "validate_domain accepts subdomains"
(validate_domain localhost) 2> /dev/null && fail "validate_domain rejects a bare host" || pass
(validate_domain 'exa mple.com') 2> /dev/null && fail "validate_domain rejects spaces" || pass
(validate_php_version 8.4) && pass || fail "validate_php_version accepts 8.4"
(validate_php_version 8) 2> /dev/null && fail "validate_php_version rejects a bare major" || pass

# ---------------------------------------------------------------------------
# core: drivers and dispatch
# ---------------------------------------------------------------------------

assert_eq "driver_for reads server.conf" "mariadb" "$(driver_for database)"
assert_eq "driver_for falls back to the default" "bun" "$(driver_for js)"
assert_eq "version matches the VERSION file" "$(tr -d '[:space:]' < "${MANDAL_ROOT}/../VERSION")" "$(mandal_version)"
assert_json "version --json" "$(cmd_version --json)" '.version' "$(mandal_version)"
(mandal_dispatch no:such:command) > /dev/null 2>&1
assert_eq "unknown commands exit 64" "64" "$?"

# ---------------------------------------------------------------------------
# inventory: nginx
# ---------------------------------------------------------------------------

nginx="$(inv_nginx)"
assert_json "nginx lists every site" "$nginx" '.sites | length' "3"
assert_json "nginx reads the managed marker" "$nginx" '.sites[] | select(.name == "shop.conf") | .managed_app' "shop"
assert_json "nginx marks foreign sites unmanaged" "$nginx" '.sites[] | select(.name == "legacy") | .managed_app' "null"
assert_json "nginx collects server names across blocks" "$nginx" '.sites[] | select(.name == "shop.conf") | .server_names | join(",")' "shop.example.com,www.shop.example.com"
assert_json "nginx collects listen ports" "$nginx" '.sites[] | select(.name == "shop.conf") | .ports | join(",")' "80,443"
assert_json "nginx counts redirects" "$nginx" '.sites[] | select(.name == "shop.conf") | .redirects' "2"
assert_json "nginx detects ssl" "$nginx" '.sites[] | select(.name == "shop.conf") | .ssl' "true"
assert_json "nginx reads the document root" "$nginx" '.sites[] | select(.name == "shop.conf") | .root' "/home/shop/current/public"
assert_json "nginx sees enabled sites" "$nginx" '.sites[] | select(.name == "shop.conf") | .enabled' "true"
assert_json "nginx sees disabled sites" "$nginx" '.sites[] | select(.name == "legacy") | .enabled' "false"
assert_json "nginx ignores catch-all server names" "$nginx" '.sites[] | select(.name == "default") | .server_names | length' "0"

# ---------------------------------------------------------------------------
# inventory: php
# ---------------------------------------------------------------------------

php="$(inv_php)"
assert_json "php lists versions" "$php" '.versions | join(",")' "8.3,8.5"
assert_json "php lists pools across versions" "$php" '.pools | length' "3"
assert_json "php reads the pool user" "$php" '.pools[] | select(.name == "shop") | .user' "shop"
assert_json "php reads the pool socket" "$php" '.pools[] | select(.name == "shop") | .listen' "/run/php/php8.5-fpm-shop.sock"
assert_json "php tags the pool version" "$php" '.pools[] | select(.name == "shop") | .php' "8.5"
assert_json "php reads the managed marker" "$php" '.pools[] | select(.name == "shop") | .managed_app' "shop"
assert_json "php marks the stock pool unmanaged" "$php" '.pools[] | select(.name == "www" and .php == "8.3") | .managed_app' "null"

# ---------------------------------------------------------------------------
# inventory: supervisor
# ---------------------------------------------------------------------------

supervisor="$(inv_supervisor)"
assert_json "supervisor lists programs" "$supervisor" '.programs | length' "2"
assert_json "supervisor reads the command" "$supervisor" '.programs[] | select(.name == "shop-worker") | .command' "/usr/bin/php8.5 /home/shop/current/artisan queue:work --sleep=3 --tries=3"
assert_json "supervisor reads numprocs as a number" "$supervisor" '.programs[] | select(.name == "shop-worker") | .numprocs' "2"
assert_json "supervisor reads process states" "$supervisor" '.processes[] | select(.process == "shop-worker:shop-worker_00") | .state' "RUNNING"

# ---------------------------------------------------------------------------
# inventory: certificates, firewall, fail2ban
# ---------------------------------------------------------------------------

certs="$(inv_certificates)"
assert_json "certbot lists certificates" "$certs" '.certificates | length' "2"
assert_json "certbot splits domains" "$certs" '.certificates[0].domains | join(",")' "shop.example.com,www.shop.example.com"
assert_json "certbot reads the expiry" "$certs" '.certificates[0].expiry' "2027-01-15 10:22:31+00:00"
assert_json "certbot reads the validity" "$certs" '.certificates[1].status' "INVALID: EXPIRED"

firewall="$(inv_firewall)"
assert_json "ufw status" "$firewall" '.status' "active"
assert_json "ufw default incoming" "$firewall" '.default_incoming' "deny"
assert_json "ufw rules from show added" "$firewall" '.rules | join(";")' "allow 22/tcp;allow 80/tcp;allow 443/tcp;allow from 10.0.0.0/8 to any port 3306"

fail2ban="$(inv_fail2ban)"
assert_json "fail2ban lists jails" "$fail2ban" '.jails | map(.name) | join(",")' "sshd,nginx-http-auth"
assert_json "fail2ban counts bans" "$fail2ban" '.jails[] | select(.name == "sshd") | .banned' "3"

# ---------------------------------------------------------------------------
# inventory: apps and system
# ---------------------------------------------------------------------------

apps="$(inv_apps)"
assert_json "apps lists manifests" "$apps" 'length' "2"
assert_json "apps merges manifest fields" "$apps" '.[] | select(.name == "shop") | .php' "8.5"
assert_json "apps reads the current release" "$apps" '.[] | select(.name == "shop") | .current_release' "20261027120000"
assert_json "apps counts releases" "$apps" '.[] | select(.name == "shop") | .releases' "2"
assert_json "apps reads the deploy key" "$apps" '.[] | select(.name == "shop") | .deploy_key' "ssh-ed25519 AAAATESTKEY shop@server"
assert_json "apps without a home have no release" "$apps" '.[] | select(.name == "api") | .current_release' "null"

system="$(inv_system)"
assert_json "system reads the OS id" "$system" '.os.id' "ubuntu"
assert_json "system reads the OS version" "$system" '.os.version' "24.04"
assert_json "system reads reboot-required" "$system" '.reboot_required' "true"
assert_json "system reads reboot packages" "$system" '.reboot_packages | join(",")' "linux-image-generic,libc6"

mandal_json="$(inv_mandal)"
assert_json "mandal reports provisioned" "$mandal_json" '.provisioned' "true"
assert_json "mandal reports drivers" "$mandal_json" '.drivers.cache' "dragonfly"

# ---------------------------------------------------------------------------
# inventory: whole document
# ---------------------------------------------------------------------------

inventory="$(cmd_inventory)"
assert_json "inventory is one object with every section" "$inventory" 'keys | join(",")' "apps,cache,certificates,database,docker,fail2ban,firewall,js,mandal,nginx,php,services,supervisor,system"
assert_json "inventory reports the absent cache honestly" "$inventory" '.cache.installed' "false"

# ---------------------------------------------------------------------------
# provision --check
# ---------------------------------------------------------------------------

check="$(provision_check)"
assert_json "check accepts Ubuntu 24.04" "$check" '.os.supported' "true"
assert_json "check has no conflicts on a clean fixture" "$check" '.conflicts | length' "0"
assert_json "check is ok" "$check" '.ok' "true"
assert_json "check plans to install missing components" "$check" '.components[] | select(.name == "nginx") | .action' "install"
assert_json "check reads sshd settings" "$check" '.ssh.password_authentication' "yes"
assert_json "check reads the ssh port" "$check" '.ssh.port' "2222"
assert_json "check counts existing sites" "$check" '.existing.sites' "3"
assert_json "check notes the enabled stock nginx site" "$check" '.notes | length' "1"
assert_json "check does not mention cloud-init when absent" "$check" '.notes | map(select(test("cloud-init"))) | length' "0"

check="$(MANDAL_OS_RELEASE="${FIXTURES}/os-release-debian" provision_check)"
assert_json "check rejects other distributions" "$check" '.os.supported' "false"
assert_json "check is not ok on an unsupported OS" "$check" '.ok' "false"

(cmd_provision --bogus) > /dev/null 2>&1
assert_eq "provision rejects unknown options" "64" "$?"

# ---------------------------------------------------------------------------
# system helpers
# ---------------------------------------------------------------------------

f="${TMP}/files/test.conf"
write_file "$f" 0644 <<< "one" > /dev/null
assert_eq "write_file creates the file" "one" "$(cat "$f")"
assert_eq "write_file reports unchanged content" "+ ${f} unchanged" "$(write_file "$f" 0644 <<< "one")"
write_file "$f" 0644 <<< "two" > /dev/null
assert_eq "write_file replaces content" "two" "$(cat "$f")"
assert_eq "write_file backs up the previous version" "1" "$(find "$MANDAL_CONFIG_BACKUP_DIR" -type f -name 'test.conf' | wc -l)"
ensure_line "$f" "three" > /dev/null
ensure_line "$f" "three" > /dev/null
assert_eq "ensure_line appends once" "1" "$(grep -c '^three$' "$f")"
assert_eq "swap_present reads the swaps fixture" "1" "$(swap_present; echo $?)"
MANDAL_PROC_SWAPS="${FIXTURES}/swaps-present" swap_present && pass || fail "swap_present detects an existing swap"
assert_eq "dry run skips commands but echoes them" "+ rm -rf /nothing" "$(MANDAL_DRY_RUN=1 run_cmd rm -rf /nothing)"

# ---------------------------------------------------------------------------
# php version discovery (fake apt-cache: 8.6 lacks redis)
# ---------------------------------------------------------------------------

assert_eq "php_available_versions lists the PPA" "8.3 8.4 8.5 8.6" "$(php_available_versions | tr '\n' ' ' | sed 's/ $//')"
assert_eq "php_newest_supported_version skips a version missing an extension" "8.5" "$(php_newest_supported_version)"
assert_eq "php_packages_for includes opcache only when it exists" "php8.4-opcache" "$(php_packages_for 8.4 | grep opcache)"
assert_eq "php_packages_for omits opcache for 8.5" "" "$(php_packages_for 8.5 | grep opcache || true)"

# ---------------------------------------------------------------------------
# provision --dry-run
# ---------------------------------------------------------------------------

dry="$(MANDAL_DRY_RUN=1 provision_apply 2>&1)"
dry_status=$?
assert_eq "dry run completes" "0" "$dry_status"
[[ "$dry_status" -eq 0 ]] || printf '%s\n' "$dry" | tail -n 8 | sed 's/^/      | /'
for marker in "==> Base packages" "==> Swap" "fallocate -l 2048M /swapfile" "==> nginx" "sites-available/mandal-default" "==> PHP" "+ PHP version: 8.5" "apt-get install" "php8.5-fpm" "update-alternatives --set php /usr/bin/php8.5" "==> Composer" "==> Database (mariadb)" "mariadb-server" "unix_socket" "==> Cache (dragonfly)" "dragonfly_amd64.deb" "--requirepass=" "==> JavaScript tooling (bun)" "bun-linux-x64.zip" "==> Docker" "docker.sources" "docker-compose-plugin" "==> fail2ban" "jail.d/mandal.local" "==> Automatic security updates" "apt.conf.d/52-mandal" "==> Firewall (ufw)" "ufw allow 2222/tcp" "ufw allow 443/tcp" "==> SSH hardening" "10-mandal.conf" "Provisioning finished."; do
    if [[ "$dry" == *"$marker"* ]]; then pass; else fail "dry run mentions: $marker"; fi
done
[[ "$dry" == *"ufw --force enable"* ]] && fail "dry run does not re-enable an active ufw" || pass
assert_eq "dry run leaves the system untouched" "" "$(ls "$MANDAL_APT_SOURCES_DIR")"
assert_eq "dry run writes no provision log" "0" "$(find "$MANDAL_LOG_DIR" -name 'provision-*.log' 2> /dev/null | wc -l)"
ssh_pos="${dry%%==> SSH hardening*}"
[[ "$ssh_pos" == *"==> Firewall"* && "$ssh_pos" == *"==> PHP"* ]] && pass || fail "SSH hardening runs after everything else"

skip="$(MANDAL_DRY_RUN=1 MANDAL_SKIP_SSH_HARDENING=1 provision_apply 2>&1)"
[[ "$skip" == *"skipped (--skip-ssh-hardening)"* ]] && pass || fail "ssh hardening can be skipped"

(provision_set_driver database=nope) > /dev/null 2>&1 && fail "unknown drivers are rejected" || pass
provision_set_driver database=mariadb > /dev/null
assert_eq "chosen drivers are recorded in server.conf" "mariadb" "$(conf_get "$MANDAL_SERVER_CONF" DATABASE)"

MANDAL_OS_RELEASE="${FIXTURES}/os-release-debian" bash -c 'source "'"${MANDAL_ROOT}"'/lib/core.sh"; mandal_load_commands; load_all_drivers; is_root(){ return 0; }; MANDAL_DRY_RUN=1 provision_apply' > /dev/null 2>&1
assert_eq "provisioning refuses an unsupported OS" "1" "$?"

# ---------------------------------------------------------------------------

printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
[[ "$FAILED" -eq 0 ]]
