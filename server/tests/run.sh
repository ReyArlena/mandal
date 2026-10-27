#!/usr/bin/env bash
#
# Tests for the mandal CLI. They run on any machine with bash and jq: all
# system paths point at the fixtures directory and the tools mandal shells
# out to are replaced by small scripts in fixtures/bin.
#
#   server/tests/run.sh

# shellcheck disable=SC2015,SC2016,SC2329
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
export MANDAL_BACKUP_DIR="${TMP}/backups"
export MANDAL_BACKUP_CRON="${TMP}/cron.d/mandal-backups"
export MANDAL_BACKUP_LOCK="${TMP}/backup.lock"
export MANDAL_NGINX_LOG_DIR="${TMP}/log/nginx"
export MANDAL_SUPERVISOR_LOG_DIR="${TMP}/log/supervisor"
export MANDAL_VAR_LOG="${TMP}/log"
export MANDAL_DEPLOY_LOG_DIR="${TMP}/log/mandal/deploys"
export MANDAL_UPDATES_CACHE="${TMP}/cache"
export MANDAL_UNATTENDED_LOG="${TMP}/log/unattended-upgrades.log"
export MANDAL_APT_HISTORY="${TMP}/log/apt/history.log"
export MANDAL_BOOT_DIR="${TMP}/boot"
export MANDAL_RELEASE_UPGRADES_CACHE="${TMP}/release-upgrade"
export MANDAL_PHP_PPA_URL="file://${FIXTURES}/ppa"
mkdir -p "${MANDAL_APT_SOURCES_DIR}"

# Only the fakes and a fixed set of real tools are on PATH, so whatever the
# host has installed (bun, docker, nginx...) cannot leak into the results.
mkdir -p "${TMP}/bin"
for tool in bash sh jq awk grep sed tr cut find wc basename dirname readlink id hostname uname nproc df mktemp mv chmod chown cat date sort uniq head tail tee ls touch base64 sha256sum tar gzip getent stat flock curl seq xxd printf mkdir cp rm ln env install; do
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
assert_json "nginx marks app sites as managed by an app" "$nginx" '.sites[] | select(.name == "shop.conf") | .managed' "app"
assert_json "nginx leaves foreign sites without a manager" "$nginx" '.sites[] | select(.name == "legacy") | .managed' "null"
printf '# managed-by: mandal\nserver {\n    listen 80 default_server;\n    server_name _;\n    return 444;\n}\n' > "${TMP}/mandal-default"
assert_eq "nginx marks mandal's own sites as managed by the server" "	server" "$(nginx_site_tsv "${TMP}/mandal-default" | cut -f1,2)"
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
assert_json "php does not list the [global] drop-in as a pool" "$php" '[.pools[].name] | index("global")' "null"
assert_json "php reads the pool user" "$php" '.pools[] | select(.name == "shop") | .user' "shop"
assert_json "php reads the pool socket" "$php" '.pools[] | select(.name == "shop") | .listen' "/run/php/php8.5-fpm-shop.sock"
assert_json "php tags the pool version" "$php" '.pools[] | select(.name == "shop") | .php' "8.5"
assert_json "php reads the managed marker" "$php" '.pools[] | select(.name == "shop") | .managed_app' "shop"
assert_json "php marks the stock pool unmanaged" "$php" '.pools[] | select(.name == "www" and .php == "8.3") | .managed_app' "null"
assert_json "php marks app pools as managed by an app" "$php" '.pools[] | select(.name == "shop") | .managed' "app"
assert_json "php leaves the stock pool without a manager" "$php" '.pools[] | select(.name == "www" and .php == "8.3") | .managed' "null"

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
assert_json "system reads the OS version" "$system" '.os.version' "26.04"
assert_json "system reads reboot-required" "$system" '.reboot_required' "true"
assert_json "system reads reboot packages" "$system" '.reboot_packages | join(",")' "linux-image-generic,libc6"

mandal_json="$(inv_mandal)"
assert_json "mandal reports provisioned" "$mandal_json" '.provisioned' "true"
unprov="${TMP}/unprovisioned.conf"
printf 'DATABASE=mariadb\n' > "$unprov"
assert_json "mandal is not provisioned until PROVISIONED_AT is recorded" "$(MANDAL_SERVER_CONF="$unprov" inv_mandal)" '.provisioned' "false"
assert_json "mandal reports drivers" "$mandal_json" '.drivers.cache' "dragonfly"

# ---------------------------------------------------------------------------
# inventory: whole document
# ---------------------------------------------------------------------------

inventory="$(cmd_inventory)"
assert_json "inventory is one object with every section" "$inventory" 'keys | join(",")' "apps,backups,cache,certificates,database,docker,fail2ban,firewall,js,mandal,nginx,php,services,supervisor,system"
assert_json "inventory reports the absent cache honestly" "$inventory" '.cache.installed' "false"

# ---------------------------------------------------------------------------
# provision --check
# ---------------------------------------------------------------------------

check="$(provision_check)"
assert_json "check accepts Ubuntu 26.04" "$check" '.os.supported' "true"
assert_json "check has no conflicts on a clean fixture" "$check" '.conflicts | length' "0"
# db_list under pipefail: only system schemas present, then one user database
set -o pipefail
dbs="$(db_list)"
assert_json "db_list is valid JSON when every schema is a system one" "$dbs" '.databases | length' "0"
assert_json "db_list lists users" "$dbs" '.users | map(.user) | join(",")' "shop"
dbs="$(MANDAL_FAKE_DBS=$'information_schema\nmysql\nshop' db_list)"
assert_json "db_list filters system schemas" "$dbs" '.databases | join(",")' "shop"
assert_json "database inventory stays valid JSON under pipefail" "$(inv_database)" '.installed' "true"
assert_json "firewall inventory stays valid JSON under pipefail" "$(MANDAL_FAKE_UFW_EMPTY=1 inv_firewall)" '.rules | length' "0"
assert_json "supervisor inventory stays valid JSON under pipefail" "$(inv_supervisor)" '.processes | length' "3"
assert_json "whole inventory is valid JSON under pipefail" "$(cmd_inventory)" 'keys | length' "15"
set +o pipefail

assert_eq "ssh_setting reads one value without exiting early" "2222" "$(ssh_setting port)"
assert_eq "port_listener strips the ss decoration" "nginx" "$(port_listener 80)"
assert_eq "port_listener is empty for a free port" "" "$(port_listener 3306)"
assert_json "check is ok" "$check" '.ok' "true"
assert_json "check plans to install missing components" "$check" '.components[] | select(.name == "nginx") | .action' "install"
assert_json "check reads sshd settings" "$check" '.ssh.password_authentication' "yes"
assert_json "check reads the ssh port" "$check" '.ssh.port' "2222"
assert_json "check counts existing sites" "$check" '.existing.sites' "3"
assert_json "check notes the enabled stock nginx site" "$check" '.notes | length' "1"
assert_json "check does not mention cloud-init when absent" "$check" '.notes | map(select(test("cloud-init"))) | length' "0"

check="$(MANDAL_OS_RELEASE="${FIXTURES}/os-release-noble" provision_check)"
assert_json "check rejects Ubuntu 24.04" "$check" '.os.supported' "false"

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
MANDAL_DRY_RUN=1 download https://example.com/file.tar.gz > /dev/null
[[ -n "${DOWNLOADED_FILE:-}" && "$DOWNLOADED_FILE" != *"download"* ]] && pass || fail "download leaves a clean path in DOWNLOADED_FILE"
MANDAL_DRY_RUN=1 download https://example.com/pkg.deb .deb > /dev/null
[[ "$DOWNLOADED_FILE" == *.deb ]] && pass || fail "download keeps the requested suffix"
sigpipe_failures=0
for _ in {1..200}; do
    secret="$(random_secret 40)" || sigpipe_failures=$((sigpipe_failures + 1))
    [[ "$secret" =~ ^[A-Za-z0-9]{40}$ ]] || sigpipe_failures=$((sigpipe_failures + 1))
done
assert_eq "random_secret never fails under pipefail" "0" "$sigpipe_failures"
cache_password > /dev/null
[[ "$CACHE_PASSWORD" =~ ^[A-Za-z0-9]{40}$ ]] && pass || fail "cache_password generates a clean 40-character secret"
first_password="$CACHE_PASSWORD"
cache_password > /dev/null
assert_eq "cache_password is stable once written" "$first_password" "$CACHE_PASSWORD"

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
for marker in "==> Base packages" "==> Swap" "fallocate -l 2048M /swapfile" "sysctl -q -p /etc/sysctl.d/60-mandal.conf" "==> nginx" "sites-available/mandal-default" "==> PHP" "+ PHP version: 8.5" "apt-get install" "php8.5-fpm" "update-alternatives --set php /usr/bin/php8.5" "==> Composer" "==> Database (mariadb)" "mariadb-server" "unix_socket" "==> Cache (dragonfly)" "dragonfly_amd64.deb" "--requirepass=" "==> JavaScript tooling (bun)" "bun-linux-x64.zip" "==> Docker" "docker.sources" "docker-compose-plugin" "==> fail2ban" "jail.d/mandal.local" "==> Automatic security updates" "apt.conf.d/52-mandal" "==> Firewall (ufw)" "ufw allow 2222/tcp" "ufw allow 443/tcp" "systemctl enable --now --quiet ufw" "==> SSH hardening" "10-mandal.conf" "Provisioning finished."; do
    if [[ "$dry" == *"$marker"* ]]; then pass; else fail "dry run mentions: $marker"; fi
done
[[ "$dry" == *"ufw --force enable"* ]] && fail "dry run does not re-enable an active ufw" || pass
unattended="$(unattended_render_config)"
[[ "$unattended" == *'"origin=Ubuntu,archive=${distro_codename}-security";'* && "$unattended" == *'"origin=LP-PPA-ondrej-php";'* && "$unattended" == *'"origin=Docker";'* ]] && pass || fail "automatic updates cover Ubuntu security, the PHP PPA and Docker"
[[ "$unattended" == *'Automatic-Reboot "false"'* ]] && pass || fail "automatic updates never reboot"
assert_eq "dry run leaves the system untouched" "" "$(ls "$MANDAL_APT_SOURCES_DIR")"
assert_eq "dry run writes no provision log" "0" "$(find "$MANDAL_LOG_DIR" -name 'provision-*.log' 2> /dev/null | wc -l)"
ssh_pos="${dry%%==> SSH hardening*}"
[[ "$ssh_pos" == *"==> Firewall"* && "$ssh_pos" == *"==> PHP"* ]] && pass || fail "SSH hardening runs after everything else"

[[ "$dry" == *"add-apt-repository -y ppa:ondrej/php"* ]] && pass || fail "dry run adds the PHP PPA when it supports the release"
no_ppa="$(MANDAL_PHP_PPA_URL="file://${TMP}/no-ppa" MANDAL_DRY_RUN=1 provision_apply 2>&1)"
assert_eq "dry run completes without the PHP PPA" "0" "$?"
[[ "$no_ppa" == *"PHP PPA has no packages for resolute yet"* ]] && pass || fail "the PHP PPA is skipped when it has no packages for the release"
[[ "$no_ppa" == *"add-apt-repository"* ]] && fail "no PPA is added for an unsupported release" || pass
[[ "$no_ppa" == *"docker.sources"* && "$no_ppa" == *"+ PHP version: 8.5"* ]] && pass || fail "the rest of provisioning runs without the PHP PPA"

ngx="${TMP}/nginx-resolute"
mkdir -p "$ngx"
printf 'http {\n\tserver_tokens build; # Recommended practice is to turn this off\n\t# client_max_body_size 1m;\n}\n' > "${ngx}/nginx.conf"
MANDAL_NGINX_DIR="$ngx" nginx_conf_sets server_tokens && pass || fail "nginx_conf_sets finds a directive nginx.conf sets"
MANDAL_NGINX_DIR="$ngx" nginx_conf_sets client_max_body_size && fail "nginx_conf_sets ignores commented-out directives" || pass
ngx_dry="$(MANDAL_NGINX_DIR="$ngx" MANDAL_DRY_RUN=1 prov_nginx 2>&1)"
[[ "$ngx_dry" == *"nginx.conf already sets server_tokens; leaving it there"* ]] && pass || fail "conf.d does not repeat a directive nginx.conf sets"
[[ "$ngx_dry" == *"already sets client_max_body_size"* ]] && fail "conf.d still sets directives nginx.conf leaves out" || pass

skip="$(MANDAL_DRY_RUN=1 MANDAL_SKIP_SSH_HARDENING=1 provision_apply 2>&1)"
[[ "$skip" == *"skipped (--skip-ssh-hardening)"* ]] && pass || fail "ssh hardening can be skipped"

(provision_set_driver database=nope) > /dev/null 2>&1 && fail "unknown drivers are rejected" || pass
provision_set_driver database=mariadb > /dev/null
assert_eq "chosen drivers are recorded in server.conf" "mariadb" "$(conf_get "$MANDAL_SERVER_CONF" DATABASE)"

# ---------------------------------------------------------------------------
# apps: templates and app:create --dry-run
# ---------------------------------------------------------------------------

assert_eq "hostnames for redirect-to-naked" "example.com www.example.com" "$(app_hostnames redirect-to-naked example.com | tr '\n' ' ' | sed 's/ $//')"
assert_eq "hostnames for naked-only" "example.com" "$(app_hostnames naked-only example.com | tr '\n' ' ' | sed 's/ $//')"
assert_eq "primary hostnames for redirect-to-www" "www.example.com www.example.org" "$(app_primary_hostnames redirect-to-www example.com example.org | tr '\n' ' ' | sed 's/ $//')"
assert_eq "redirect hostnames for redirect-to-naked" "www.example.com www.example.org" "$(app_redirect_hostnames redirect-to-naked example.com example.org | tr '\n' ' ' | sed 's/ $//')"
assert_eq "no redirect hostnames for naked-only" "" "$(app_redirect_hostnames naked-only example.com)"

vhost="$(app_render_vhost shop 8.5 redirect-to-naked shop.example.com shop.example.org)"
[[ "$vhost" == "# managed-by: mandal app=shop"* ]] && pass || fail "vhost starts with the managed marker"
[[ "$vhost" == *"server_name www.shop.example.com www.shop.example.org;"* ]] && pass || fail "vhost redirects the www names"
[[ "$vhost" == *'return 301 $scheme://shop.example.com$request_uri;'* ]] && pass || fail "vhost redirects to the naked primary domain"
[[ "$vhost" == *"server_name shop.example.com shop.example.org;"* ]] && pass || fail "vhost serves the naked names"
[[ "$vhost" == *"root ${MANDAL_HOME_DIR}/shop/current/public;"* ]] && pass || fail "vhost roots at current/public"
[[ "$vhost" == *"fastcgi_pass unix:/run/php/php8.5-fpm-shop.sock;"* ]] && pass || fail "vhost uses the app socket"
[[ "$vhost" == *"access_log /var/log/nginx/shop-access.log;"* ]] && pass || fail "vhost has per-app logs"
assert_eq "every server block in the vhost hides the nginx version" "2" "$(grep -c 'server_tokens off;' <<< "$vhost")"
www_vhost="$(app_render_vhost shop 8.5 redirect-to-www shop.example.com)"
[[ "$www_vhost" == *'return 301 $scheme://www.shop.example.com$request_uri;'* ]] && pass || fail "redirect-to-www sends the naked name to www"
naked_vhost="$(app_render_vhost shop 8.5 naked-only shop.example.com)"
[[ "$naked_vhost" != *"return 301"* ]] && pass || fail "naked-only has no redirect block"

pool="$(app_render_pool shop 8.5)"
[[ "$pool" == *"[shop]"* && "$pool" == *"user = shop"* && "$pool" == *"listen = /run/php/php8.5-fpm-shop.sock"* ]] && pass || fail "pool has the app user and socket"
[[ "$pool" == *"php_admin_value[open_basedir] = ${MANDAL_HOME_DIR}/shop:/tmp:/dev/urandom"* ]] && pass || fail "pool restricts open_basedir"
[[ "$pool" == *"listen.owner = www-data"* ]] && pass || fail "pool socket is owned by nginx"

env_file="$(app_render_env shop shop.example.com shop shop secret cachepass)"
[[ "$env_file" == *"APP_URL=https://shop.example.com"* && "$env_file" == *'DB_PASSWORD="secret"'* && "$env_file" == *'REDIS_PASSWORD="cachepass"'* && "$env_file" == *"APP_KEY=base64:"* ]] && pass || fail "env file has the app settings"

cron="$(app_render_cron shop 8.5)"
[[ "$cron" == *"* * * * * /usr/bin/php8.5 ${MANDAL_HOME_DIR}/shop/current/artisan schedule:run"* ]] && pass || fail "cron runs the scheduler with the versioned php"

(validate_branch main) && pass || fail "validate_branch accepts main"
(validate_branch feature/x-1) && pass || fail "validate_branch accepts slashes"
(validate_branch 'a;b') 2> /dev/null && fail "validate_branch rejects shell characters" || pass
(validate_repo acme/shop) && pass || fail "validate_repo accepts owner/name"
(validate_repo 'git@github.com:acme/shop') 2> /dev/null && fail "validate_repo rejects URLs" || pass
(validate_repo '../etc') 2> /dev/null && fail "validate_repo rejects dot segments" || pass
(validate_www_mode naked-only) && pass || fail "validate_www_mode accepts naked-only"
(validate_www_mode both) 2> /dev/null && fail "validate_www_mode rejects unknown modes" || pass

create="$(php_default_version() { echo 8.5; }; cmd_app_create --name=blog --domain=blog.example.com --alias=blog.example.org --repo=acme/blog --dry-run 2>&1)"
create_status=$?
assert_eq "app:create dry run completes" "0" "$create_status"
[[ "$create_status" -eq 0 ]] || printf '%s\n' "$create" | tail -n 6 | sed 's/^/      | /'
for marker in "useradd --create-home --home-dir ${MANDAL_HOME_DIR}/blog" "chmod 0750 ${MANDAL_HOME_DIR}/blog" "setfacl -m u:www-data:x" "ssh-keygen" "write ${MANDAL_PHP_DIR}/8.5/fpm/pool.d/blog.conf" "write ${MANDAL_NGINX_DIR}/sites-available/blog.conf" "sites-enabled/blog.conf" "CREATE DATABASE IF NOT EXISTS \`blog\`" "GRANT ALL PRIVILEGES ON \`blog\`.* TO 'blog'@'localhost'" "write ${MANDAL_HOME_DIR}/blog/shared/.env (0600 blog:blog)" "write ${MANDAL_HOME_DIR}/blog/deploy.sh (0750 blog:blog)" "crontab for blog" "App blog created."; do
    if [[ "$create" == *"$marker"* ]]; then pass; else fail "app:create mentions: $marker"; fi
done
[[ "$create" == *"ALTER USER 'blog'@'localhost' IDENTIFIED BY '***'"* ]] && pass || fail "app:create masks the database password in its output"
[[ "$create" != *"IDENTIFIED BY ''"* ]] && pass || fail "database password is not empty"

(cmd_app_create --name=shop --domain=x.example.com --dry-run) > /dev/null 2>&1 && fail "app:create refuses an existing app" || pass
(cmd_app_create --name=Bad --domain=x.example.com --dry-run) > /dev/null 2>&1 && fail "app:create validates the name" || pass
(cmd_app_create --name=ok --domain=x.example.com --www=sideways --dry-run) > /dev/null 2>&1 && fail "app:create validates the www mode" || pass
(cmd_app_create --name=ok --dry-run) > /dev/null 2>&1 && fail "app:create requires a domain" || pass

delete="$(cmd_app_delete --name=shop --dry-run 2>&1)"
assert_eq "app:delete dry run completes" "0" "$?"
for marker in "rm -f ${MANDAL_NGINX_DIR}/sites-enabled/shop.conf" "rm -f ${MANDAL_PHP_DIR}/8.5/fpm/pool.d/shop.conf" "DROP DATABASE IF EXISTS \`shop\`" "userdel --remove shop" "App shop removed."; do
    if [[ "$delete" == *"$marker"* ]]; then pass; else fail "app:delete mentions: $marker"; fi
done
keep="$(cmd_app_delete --name=shop --keep-database --keep-files --dry-run 2>&1)"
[[ "$keep" != *"DROP DATABASE"* && "$keep" == *"userdel shop"* ]] && pass || fail "app:delete can keep the database and files"

# ---------------------------------------------------------------------------
# deployments
# ---------------------------------------------------------------------------

export MANDAL_DEPLOY_LOG_DIR="${TMP}/deploys"
releases_json="$(deploy_releases_json shop)"
assert_json "app:releases lists releases oldest first" "$releases_json" 'map(.release) | join(",")' "20261027110000,20261027120000"
assert_json "app:releases marks the current release" "$releases_json" 'map(select(.current)) | .[0].release' "20261027120000"
assert_eq "deploy_current_release reads the symlink" "20261027120000" "$(deploy_current_release "${MANDAL_HOME_DIR}/shop")"

deploy="$(cmd_app_deploy --name=shop --dry-run 2>&1)"
deploy_status=$?
assert_eq "app:deploy dry run completes" "0" "$deploy_status"
[[ "$deploy_status" -eq 0 ]] || printf '%s\n' "$deploy" | tail -n 6 | sed 's/^/      | /'
for marker in "==> Deploying shop from acme/shop@main" "git clone --quiet --depth 1 --branch main git@github.com:acme/shop.git ${MANDAL_HOME_DIR}/shop/releases/" "GIT_SSH_COMMAND=ssh -i ${MANDAL_HOME_DIR}/shop/.ssh/id_ed25519" "ln -sfn ${MANDAL_HOME_DIR}/shop/shared/storage" "ln -sfn ${MANDAL_HOME_DIR}/shop/shared/.env" "exec bash '${MANDAL_HOME_DIR}/shop/deploy.sh'" "PHP=/usr/bin/php8.5" "mv -T ${MANDAL_HOME_DIR}/shop/current.new ${MANDAL_HOME_DIR}/shop/current" "systemctl reload-or-restart php8.5-fpm" "artisan queue:restart" "supervisorctl start shop-worker:shop-worker_01" "==> Pruning old releases (keeping 5)" "Deployed shop"; do
    if [[ "$deploy" == *"$marker"* ]]; then pass; else fail "app:deploy mentions: $marker"; fi
done
[[ "$deploy" == *"sudo -u shop -H env HOME=${MANDAL_HOME_DIR}/shop"* ]] && pass || fail "deploy steps run as the app user"
[[ "$deploy" == *"MANDAL_APP=shop RELEASE_DIR="* && "$deploy" != *"APP_NAME="* ]] && pass || fail "deploy.sh gets the app name as MANDAL_APP, never as APP_NAME"
[[ "$deploy" != *"supervisorctl start shop-worker:shop-worker_00"* ]] && pass || fail "app:deploy leaves running workers to queue:restart"
assert_eq "dry run writes no deploy log" "0" "$(find "$MANDAL_DEPLOY_LOG_DIR" -type f 2> /dev/null | wc -l)"

assert_eq "clone url defaults to ssh" "git@github.com:acme/shop.git" "$(deploy_clone_url acme/shop)"
assert_eq "clone url can use https" "https://github.com/acme/shop.git" "$(deploy_clone_url acme/shop https)"
https_create="$(php_default_version() { echo 8.5; }; cmd_app_create --name=pub --domain=pub.example.com --repo=laravel/laravel --https --dry-run 2>&1)"
[[ "$https_create" == *"App pub created."* ]] && pass || fail "app:create accepts --https"
branch_deploy="$(cmd_app_deploy --name=shop --branch=release/1.2 --commit=abc1234 --dry-run 2>&1)"
[[ "$branch_deploy" == *"--branch release/1.2"* && "$branch_deploy" == *"checkout --quiet abc1234"* ]] && pass || fail "app:deploy accepts a branch and commit"
(cmd_app_deploy --name=shop --commit='abc;rm' --dry-run) > /dev/null 2>&1 && fail "app:deploy rejects an unsafe commit" || pass
(cmd_app_deploy --name=api --dry-run) > /dev/null 2>&1 && fail "app:deploy refuses an app without a repository" || pass

rollback="$(cmd_app_rollback --name=shop --dry-run 2>&1)"
assert_eq "app:rollback dry run completes" "0" "$?"
[[ "$rollback" == *"Rolling shop back to release 20261027110000"* ]] && pass || fail "app:rollback picks the previous release"
[[ "$rollback" == *"ln -sfn ${MANDAL_HOME_DIR}/shop/releases/20261027110000 ${MANDAL_HOME_DIR}/shop/current.new"* ]] && pass || fail "app:rollback switches the symlink"
(cmd_app_rollback --name=shop --release=nope --dry-run) > /dev/null 2>&1 && fail "app:rollback validates the release name" || pass

prune_home="${TMP}/prune/home"
mkdir -p "$prune_home/releases/"{20260101000000,20260102000000,20260103000000,20260104000000}
MANDAL_DRY_RUN=1 deploy_prune "$prune_home" 2 > "${TMP}/prune.out"
assert_eq "deploy_prune removes the oldest releases beyond the limit" "20260101000000 20260102000000" "$(grep -oE '[0-9]{14}' "${TMP}/prune.out" | tr '\n' ' ' | sed 's/ $//')"

# ---------------------------------------------------------------------------
# cli:update, app:ssl, app:env
# ---------------------------------------------------------------------------

[[ "$(mandal_build_hash)" =~ ^[0-9a-f]{12}$ ]] && pass || fail "build hash is 12 hex characters"
assert_eq "build hash is stable" "$(mandal_build_hash)" "$(mandal_build_hash)"
assert_eq "build hash does not depend on the working directory" "$(mandal_build_hash)" "$(cd "$TMP" && mandal_build_hash)"
assert_json "version --json includes the build" "$(cmd_version --json)" '.build' "$(mandal_build_hash)"

archive="${TMP}/cli.tar.gz"
(cd "$MANDAL_ROOT" && tar -czf "$archive" --exclude='tests' bin lib ../VERSION 2> /dev/null || tar -czf "$archive" --exclude='tests' bin lib)
update="$(cmd_cli_update --from="$archive" --dry-run 2>&1)"
assert_eq "cli:update dry run completes" "0" "$?"
[[ "$update" == *"tar -xzf $archive"* && "$update" == *"mv "* ]] && pass || fail "cli:update extracts and swaps the install directory"
(cmd_cli_update --from=/nonexistent --dry-run) > /dev/null 2>&1 && fail "cli:update requires an existing archive" || pass

server_addresses() { printf '203.0.113.10\n'; }
resolve_addresses() { case "$1" in shop.example.com|www.shop.example.com) echo 203.0.113.10 ;; *) echo 198.51.100.1 ;; esac; }
assert_eq "dns mismatches lists names that point elsewhere" "other.example.com" "$(ssl_dns_mismatches shop.example.com www.shop.example.com other.example.com)"
ssl="$(cmd_app_ssl --name=shop --email=admin@example.com --dry-run 2>&1)"
assert_eq "app:ssl dry run completes" "0" "$?"
[[ "$ssl" == *"certbot --nginx --non-interactive --agree-tos --redirect --keep-until-expiring --expand --email admin@example.com -d shop.example.com -d www.shop.example.com"* ]] && pass || fail "app:ssl runs certbot for every host name"
(cmd_app_ssl --name=api --dry-run) > /dev/null 2>&1
assert_eq "app:ssl refuses when DNS does not point here" "3" "$?"
skip_ssl="$(cmd_app_ssl --name=api --skip-dns-check --dry-run 2>&1)"
[[ "$skip_ssl" == *"--register-unsafely-without-email -d api.example.com"* ]] && pass || fail "app:ssl can skip the DNS check and works without an email"
(cmd_app_ssl --name=shop --email='not an email' --dry-run) > /dev/null 2>&1 && fail "app:ssl validates the email" || pass

printf 'APP_ENV=production\n' > "${TMP}/new.env"
env_out="$(cmd_app_env --name=shop --from="${TMP}/new.env" --dry-run 2>&1)"
assert_eq "app:env --from dry run completes" "0" "$?"
[[ "$env_out" == *"write ${MANDAL_HOME_DIR}/shop/shared/.env (0600 shop:shop)"* ]] && pass || fail "app:env writes the env file as the app user"
printf 'A=1\x00B=2\n' > "${TMP}/bad.env"
(cmd_app_env --name=shop --from="${TMP}/bad.env" --dry-run) > /dev/null 2>&1 && fail "app:env rejects binary content" || pass

# app:deploy-script
assert_eq "app:deploy-script --default prints the template file" "$(cat "${MANDAL_ROOT}/lib/templates/deploy.sh")" "$(cmd_app_deploy_script --default)"
[[ "$(cmd_app_deploy_script --default)" == *'$MANDAL_APP'* && "$(cmd_app_deploy_script --default)" == *'$PHP artisan optimize'* ]] && pass || fail "the default deploy.sh names MANDAL_APP and runs optimize"
mkdir -p "${MANDAL_HOME_DIR}/shop"
printf '#!/usr/bin/env bash\necho custom\n' > "${MANDAL_HOME_DIR}/shop/deploy.sh"
assert_eq "app:deploy-script prints the app's script" "$(printf '#!/usr/bin/env bash\necho custom')" "$(cmd_app_deploy_script --name=shop)"
printf '#!/usr/bin/env bash\nset -e\n$PHP artisan migrate --force\n' > "${TMP}/deploy-new.sh"
script_out="$(cmd_app_deploy_script --name=shop --from="${TMP}/deploy-new.sh" --dry-run 2>&1)"
assert_eq "app:deploy-script --from dry run completes" "0" "$?"
[[ "$script_out" == *"write ${MANDAL_HOME_DIR}/shop/deploy.sh (0750 shop:shop)"* ]] && pass || fail "app:deploy-script writes deploy.sh as the app user"
printf '#!/usr/bin/env bash\nif [ -f x ]; then echo y\n' > "${TMP}/deploy-bad.sh"
bad_script="$(cmd_app_deploy_script --name=shop --from="${TMP}/deploy-bad.sh" --dry-run 2>&1)" && fail "app:deploy-script rejects a script bash cannot parse" || pass
[[ "$bad_script" == *"syntax error"* && "$bad_script" != *"${TMP}"* ]] && pass || fail "the syntax error names deploy.sh, not the upload path"
[[ "$(cmd_app_deploy_script --name=shop --reset --dry-run 2>&1)" == *"write ${MANDAL_HOME_DIR}/shop/deploy.sh (0750 shop:shop)"* ]] && pass || fail "app:deploy-script --reset writes the default"
(cmd_app_deploy_script --name=shop --reset --from="${TMP}/deploy-new.sh" --dry-run) > /dev/null 2>&1 && fail "app:deploy-script refuses --from with --reset" || pass
(cmd_app_deploy_script --name=tool --dry-run) > /dev/null 2>&1 && fail "app:deploy-script refuses Docker apps" || pass
rm -f "${MANDAL_HOME_DIR}/shop/deploy.sh"
from_create="$(php_default_version() { echo 8.5; }; cmd_app_create --name=custom --domain=custom.example.com --from="${TMP}/deploy-new.sh" --dry-run 2>&1)"
[[ "$from_create" == *"write ${MANDAL_HOME_DIR}/custom/deploy.sh (0750 custom:custom)"* ]] && pass || fail "app:create --from installs the given deploy script for a Laravel app"
(php_default_version() { echo 8.5; }; cmd_app_create --name=custom --domain=custom.example.com --from="${TMP}/deploy-bad.sh" --dry-run) > /dev/null 2>&1 && fail "app:create --from rejects a broken deploy script" || pass

# ---------------------------------------------------------------------------
# workers
# ---------------------------------------------------------------------------

program="$(worker_render shop default 8.5 "queue:work --sleep=3 --tries=3" 2)"
[[ "$program" == "# managed-by: mandal app=shop"* ]] && pass || fail "worker config has the managed marker"
[[ "$program" == *"[program:shop-default]"* && "$program" == *"command=/usr/bin/php8.5 ${MANDAL_HOME_DIR}/shop/current/artisan queue:work --sleep=3 --tries=3"* ]] && pass || fail "worker config runs artisan with the app's php"
[[ "$program" == *"user=shop"* && "$program" == *"numprocs=2"* && "$program" == *"stdout_logfile=/var/log/supervisor/shop-default.log"* ]] && pass || fail "worker config sets user, processes and log"

add="$(cmd_worker_add --app=shop --name=default --processes=2 --dry-run 2>&1)"
assert_eq "worker:add dry run completes" "0" "$?"
[[ "$add" == *"write ${MANDAL_SUPERVISOR_DIR}/shop-default.conf"* && "$add" == *"supervisorctl reread"* && "$add" == *"supervisorctl update"* ]] && pass || fail "worker:add writes the program and reloads supervisor"
horizon="$(cmd_worker_add --app=shop --name=horizon --horizon --dry-run 2>&1)"
[[ "$horizon" == *"(1 x horizon)"* ]] && pass || fail "worker:add --horizon runs horizon with one process"
(cmd_worker_add --app=shop --name=bad --command='queue:work; rm -rf /' --dry-run) > /dev/null 2>&1 && fail "worker:add rejects shell characters in the command" || pass
(cmd_worker_add --app=shop --name='Bad Name' --dry-run) > /dev/null 2>&1 && fail "worker:add validates the worker name" || pass
(cmd_worker_add --app=nope --name=x --dry-run) > /dev/null 2>&1 && fail "worker:add requires an existing app" || pass

remove="$(cmd_worker_remove --app=shop --name=worker --dry-run 2>&1)"
[[ "$remove" == *"supervisorctl stop shop-worker:*"* && "$remove" == *"rm -f ${MANDAL_SUPERVISOR_DIR}/shop-worker.conf"* ]] && pass || fail "worker:remove stops and deletes the program"
restart="$(cmd_worker_restart --app=shop --dry-run 2>&1)"
[[ "$restart" == *"supervisorctl restart shop-worker:*"* ]] && pass || fail "worker:restart restarts every program of the app"
assert_json "worker:list filters by app" "$(cmd_worker_list --app=shop)" 'map(.worker) | join(",")' "worker"
assert_json "worker:list is empty for an app without workers" "$(cmd_worker_list --app=api)" 'length' "0"

# ---------------------------------------------------------------------------
# backups
# ---------------------------------------------------------------------------

assert_eq "restored copies are not backed up again" "shop" "$(MANDAL_FAKE_DBS=$'mysql\nshop\nshop_restore_20261027041515' backup_databases | tr '\n' ' ' | sed 's/ $//')"
assert_json "backup status is unconfigured at first" "$(backup_status_json)" '.configured' "false"
assert_json "inventory carries a backups section" "$(cmd_inventory)" '.backups.configured' "false"
(cmd_backup_run --dry-run) > /dev/null 2>&1 && fail "backup:run refuses to run unconfigured" || pass

printf 'SCHEDULE=0 3 * * *\nKEEP_LOCAL=3\nKEEP_REMOTE=10\nREMOTE=true\nS3_BUCKET=my-backups\nS3_PATH=srv1\nS3_ENDPOINT=https://s3.example.com\nS3_REGION=auto\nS3_KEY=AKIAEXAMPLE\nS3_SECRET=sekret\n' > "${TMP}/backup.env"
configure="$(cmd_backup_configure --from="${TMP}/backup.env" --dry-run 2>&1)"
assert_eq "backup:configure dry run completes" "0" "$?"
[[ "$configure" == *"write ${MANDAL_ETC}/backups.conf (0600"* && "$configure" == *"write ${MANDAL_ETC}/rclone.conf (0600"* && "$configure" == *"write ${MANDAL_BACKUP_CRON}"* ]] && pass || fail "backup:configure writes the config, rclone config and cron"
[[ "$configure" != *"sekret"* ]] && pass || fail "backup:configure never prints the secret"
[[ "$(backup_render_rclone https://s3.example.com auto AKIA sek)" == *"secret_access_key = sek"* ]] && pass || fail "rclone config carries the credentials"
assert_eq "cron renders the schedule" "0 3 * * * root /opt/mandal/bin/mandal backup:run >> ${MANDAL_LOG_DIR}/backups.log 2>&1" "$(backup_render_cron '0 3 * * *' | tail -n 1)"
printf 'SCHEDULE=0 3 * * *\nREMOTE=true\nS3_BUCKET=Bad Bucket\nS3_ENDPOINT=https://s3.example.com\nS3_KEY=a\nS3_SECRET=b\n' > "${TMP}/bad.env"
(cmd_backup_configure --from="${TMP}/bad.env" --dry-run) > /dev/null 2>&1 && fail "backup:configure validates the bucket" || pass
(cmd_backup_configure --schedule='rm -rf' --dry-run) > /dev/null 2>&1 && fail "backup:configure validates the schedule" || pass

# a configured server with existing local backups
(cmd_backup_configure --schedule='30 2 * * *' --keep-local=2 --keep-remote=5 --no-remote) > /dev/null 2>&1
assert_json "backup status reflects the configuration" "$(backup_status_json)" '.schedule + "|" + (.keep_local|tostring) + "|" + (.remote.enabled|tostring)' "30 2 * * *|2|false"
mkdir -p "${MANDAL_BACKUP_DIR}/shop"
for ts in 20261020020000 20261021020000 20261022020000; do printf 'x' > "${MANDAL_BACKUP_DIR}/shop/shop-${ts}.sql.zst"; done
never_backed_up="$(set -eo pipefail; MANDAL_FAKE_DBS=$'mysql\nfresh' backup_list_json)"
assert_eq "backup:list succeeds for a database with no backups yet" "0" "$?"
assert_json "backup:list shows no files for a database never backed up" "$never_backed_up" '.[] | select(.database == "fresh") | .local | length' "0"
assert_json "backup:list shows local files" "$(MANDAL_FAKE_DBS=$'mysql\nshop' backup_list_json)" '.[0].local | map(.file) | join(",")' "shop-20261020020000.sql.zst,shop-20261021020000.sql.zst,shop-20261022020000.sql.zst"
MANDAL_DRY_RUN=1 backup_prune_local shop 2 > "${TMP}/prune.out"
assert_eq "local pruning removes the oldest beyond the limit" "shop-20261020020000.sql.zst" "$(grep -oE 'shop-[0-9]+\.sql\.zst' "${TMP}/prune.out" | tr '\n' ' ' | sed 's/ $//')"

run="$(MANDAL_FAKE_DBS=$'mysql\nshop' MANDAL_DRY_RUN=1 backup_run 2>&1)"
assert_eq "backup:run dry run completes" "0" "$?"
[[ "$run" == *"+ dump shop -> ${MANDAL_BACKUP_DIR}/shop/shop-"* && "$run" == *" ok shop "* ]] && pass || fail "backup:run dumps each database and logs the result"
[[ "$run" != *"rclone"* ]] && pass || fail "backup:run skips the remote when disabled"
(MANDAL_FAKE_DBS=$'mysql\nshop' MANDAL_DRY_RUN=1 backup_run nope) > /dev/null 2>&1 && fail "backup:run fails for an unknown database" || pass

restore="$(cmd_backup_restore --database=shop --file=shop-20261021020000.sql.zst --dry-run 2>&1)"
assert_eq "backup:restore dry run completes" "0" "$?"
[[ "$restore" == *"CREATE DATABASE IF NOT EXISTS \`shop_restore_"* && "$restore" == *"zstd -dc ${MANDAL_BACKUP_DIR}/shop/shop-20261021020000.sql.zst | mariadb shop_restore_"* && "$restore" == *"GRANT ALL PRIVILEGES ON \`shop_restore_"* ]] && pass || fail "backup:restore restores into a new database the app user can read"
(cmd_backup_restore --database=shop --file='../etc/passwd' --dry-run) > /dev/null 2>&1 && fail "backup:restore validates the file name" || pass
(cmd_backup_restore --database=shop --file=shop-20261021020000.sql.zst --into='shop;drop' --dry-run) > /dev/null 2>&1 && fail "backup:restore validates the target database" || pass

# ---------------------------------------------------------------------------
# logs
# ---------------------------------------------------------------------------

mkdir -p "$MANDAL_NGINX_LOG_DIR" "$MANDAL_SUPERVISOR_LOG_DIR" "${MANDAL_HOME_DIR}/shop/shared/storage/logs" "$MANDAL_DEPLOY_LOG_DIR/shop" "$MANDAL_VAR_LOG/apt"
for i in $(printf '%s\n' {1..300}); do printf '127.0.0.1 - - [27/Oct/2026:04:00:%02d +0000] "GET /page%d HTTP/1.1" 200 12\n' "$((i % 60))" "$i"; done > "$MANDAL_NGINX_LOG_DIR/shop-access.log"
printf '2026/10/27 04:01:00 [error] 1#1: *1 open() failed\n' > "$MANDAL_NGINX_LOG_DIR/shop-error.log"
printf 'Start-Date: 2026-10-27  02:30:01\nUpgrade: nginx:amd64 (1.24.0-1, 1.24.0-2)\nEnd-Date: 2026-10-27  02:30:05\n' > "$MANDAL_VAR_LOG/apt/history.log"
printf 'deploy one\n' > "$MANDAL_DEPLOY_LOG_DIR/shop/20261027110000.log"
printf 'deploy two\nDeployed shop\n' > "$MANDAL_DEPLOY_LOG_DIR/shop/20261027120000.log"
cat > "${MANDAL_HOME_DIR}/shop/shared/storage/logs/laravel-2026-10-27.log" <<'LOGEOF'
[2026-10-27 04:00:00] production.INFO: Scheduler ran
[2026-10-27 04:01:00] production.ERROR: Something broke {"exception":"[object] (RuntimeException(code: 0): boom at /home/shop/current/app/Foo.php:12)
[stacktrace]
#0 /home/shop/current/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1): App\\Foo->bar()
#1 {main}
"}
[2026-10-27 04:02:00] production.WARNING: Slow query
LOGEOF

sources="$(logs_sources_json)"
assert_json "sources include per-app nginx logs" "$sources" 'map(.id) | index("nginx:shop:access") != null' "true"
assert_json "sources include laravel logs" "$sources" 'map(select(.id == "laravel:shop")) | .[0].kind' "laravel"
assert_json "sources include worker logs" "$sources" 'map(.id) | index("supervisor:shop:worker") != null' "true"
assert_json "sources include the kernel journal" "$sources" 'map(.id) | index("journal:kernel") != null' "true"
assert_json "sources filter by app" "$(logs_sources_json api)" 'map(.app) | unique | join(",")' "api"
(logs_source '../../etc/passwd') > /dev/null 2>&1 && fail "sources never accept paths" || pass
(logs_source 'nginx:nope:access') > /dev/null 2>&1 && fail "unknown sources are refused" || pass

read_out="$(cmd_logs_read --source=nginx:shop:access --lines=50)"
assert_json "logs:read returns the last N lines" "$read_out" '.lines | length' "50"
assert_json "logs:read returns the newest line last" "$read_out" '.lines[-1] | test("/page300 ")' "true"
assert_json "logs:read reports a cursor" "$read_out" '.cursor | tonumber > 0' "true"
cursor="$(jq -r .cursor <<< "$read_out")"
assert_json "logs:read --after returns nothing new" "$(cmd_logs_read --source=nginx:shop:access --after="$cursor")" '.lines | length' "0"
printf '127.0.0.1 - - [27/Oct/2026:04:05:00 +0000] "GET /new HTTP/1.1" 200 12\n' >> "$MANDAL_NGINX_LOG_DIR/shop-access.log"
assert_json "logs:read --after returns only new lines" "$(cmd_logs_read --source=nginx:shop:access --after="$cursor")" '.lines | join("")' '127.0.0.1 - - [27/Oct/2026:04:05:00 +0000] "GET /new HTTP/1.1" 200 12'
assert_json "logs:read --grep filters case-insensitively" "$(cmd_logs_read --source=nginx:shop:access --grep=PAGE29 --lines=1000)" '.lines | length' "11"
assert_json "logs:read reports a missing file" "$(cmd_logs_read --source=php:shop:slow)" '.exists' "false"

laravel="$(cmd_logs_read --source=laravel:shop)"
assert_json "laravel entries are split at headers" "$laravel" '.entries | length' "3"
assert_json "laravel entries keep the stack trace attached" "$laravel" '.entries[1].text | contains("#1 {main}")' "true"
assert_json "laravel entries carry level and message" "$laravel" '.entries[1].level + "|" + .entries[1].message' "ERROR|Something broke {\"exception\":\"[object] (RuntimeException(code: 0): boom at /home/shop/current/app/Foo.php:12)"
assert_json "laravel entries filter by level" "$(cmd_logs_read --source=laravel:shop --level=error)" '.entries | length' "1"
assert_json "laravel entries filter by text" "$(cmd_logs_read --source=laravel:shop --grep=slow)" '.entries[0].level' "WARNING"

assert_json "deploy logs read the newest deployment" "$(cmd_logs_read --source=deploy:shop)" '.lines | join("|")' "deploy two|Deployed shop"
assert_json "journal sources report absence without journalctl" "$(cmd_logs_read --source=journal:kernel)" '.exists' "false"
printf 'TYPE=docker\nDOMAIN=tool.example.com\n' > "${MANDAL_ETC}/apps/tool.conf"
assert_json "docker apps list their containers instead of Laravel logs" "$(logs_sources_json tool)" 'map(.id) | join(",")' "nginx:tool:access,nginx:tool:error,docker:tool"
assert_json "docker sources report absence without docker" "$(cmd_logs_read --source=docker:tool)" '.exists' "false"
mkdir -p "${TMP}/docker-bin" "${TMP}/srv/tool"
touch "${TMP}/srv/tool/compose.yml"
cat > "${TMP}/docker-bin/docker" <<'DOCKEREOF'
#!/usr/bin/env bash
printf '%s\n' "$*" > "${MANDAL_FAKE_DOCKER_ARGS}"
printf 'app-1  | 2026-10-27T04:00:00.000000000Z listening on :80\napp-1  | 2026-10-27T04:00:01.000000000Z GET /health\n'
DOCKEREOF
chmod +x "${TMP}/docker-bin/docker"
export MANDAL_FAKE_DOCKER_ARGS="${TMP}/docker-args"
docker_read="$(PATH="${TMP}/docker-bin:${PATH}" MANDAL_DOCKER_DIR="${TMP}/srv" cmd_logs_read --source=docker:tool --lines=20)"
assert_json "docker logs return the container output" "$docker_read" '.lines | length' "2"
assert_eq "docker logs read the app's compose project" "compose --project-directory ${TMP}/srv/tool --project-name tool logs --no-color --timestamps --tail 20" "$(cat "$MANDAL_FAKE_DOCKER_ARGS")"
assert_json "docker logs filter by text" "$(PATH="${TMP}/docker-bin:${PATH}" MANDAL_DOCKER_DIR="${TMP}/srv" cmd_logs_read --source=docker:tool --grep=HEALTH)" '.lines | length' "1"
docker_cursor="$(jq -r .cursor <<< "$docker_read")"
PATH="${TMP}/docker-bin:${PATH}" MANDAL_DOCKER_DIR="${TMP}/srv" cmd_logs_read --source=docker:tool --after="$docker_cursor" > /dev/null
assert_eq "docker live tail asks only for output after the cursor" "compose --project-directory ${TMP}/srv/tool --project-name tool logs --no-color --timestamps --since ${docker_cursor}" "$(cat "$MANDAL_FAKE_DOCKER_ARGS")"
rm -f "${MANDAL_ETC}/apps/tool.conf"

(cmd_logs_read --source=nginx:shop:access --lines=99999) > /dev/null 2>&1 && fail "logs:read caps the line count" || pass
(cmd_logs_read --source=nginx:shop:access --since=yesterday) > /dev/null 2>&1 && fail "logs:read validates --since" || pass

# ---------------------------------------------------------------------------
# databases
# ---------------------------------------------------------------------------

export MANDAL_FAKE_USERS=$'shop\tlocalhost\nreporter\tlocalhost\nremote\t10.0.0.5'
dbl="$(cmd_db_list)"
assert_json "db:list leaves out system schemas" "$dbl" '.databases | map(.name) | join(",")' "reports,shop"
assert_json "db:list reports sizes and table counts" "$dbl" '.databases[] | select(.name == "shop") | "\(.size) \(.tables)"' "49152 12"
assert_json "db:list marks the database an app uses" "$dbl" '.databases | map("\(.name)=\(.app)") | join(",")' "reports=null,shop=shop"
assert_json "db:list lists each user's databases" "$dbl" '.users[] | select(.user == "reporter") | .databases | join(",")' "reports,shop_archive"
assert_json "db:list marks the app's user" "$dbl" '.users | map("\(.user)=\(.app)") | join(",")' "shop=shop,reporter=null,remote=null"
assert_json "db:list includes the server status" "$dbl" '.driver' "mariadb"

pw="${TMP}/db-password.conf"
printf 'PASSWORD=Str0ng-and-long-enough\n' > "$pw"
out="$(cmd_db_user_create --name=analyst --database=reports --from="$pw" --dry-run 2>&1)"
[[ "$out" == *"CREATE USER 'analyst'@'localhost' IDENTIFIED BY '***'"* && "$out" == *"GRANT ALL PRIVILEGES ON \`reports\`.* TO 'analyst'@'localhost'"* ]] && pass || fail "db:user:create creates a local user with access to its databases"
[[ "$out" != *"Str0ng-and-long-enough"* ]] && pass || fail "db:user:create never prints the password"
printf 'PASSWORD=short\n' > "$pw"
(cmd_db_user_create --name=analyst --from="$pw" --dry-run) > /dev/null 2>&1 && fail "db:user:create rejects a weak password" || pass
printf "PASSWORD=quote'inside-the-password\n" > "$pw"
(cmd_db_user_create --name=analyst --from="$pw" --dry-run) > /dev/null 2>&1 && fail "db:user:create rejects quotes in the password" || pass
(cmd_db_user_create --name=analyst --password=x --dry-run) > /dev/null 2>&1 && fail "db:user:create never takes the password as an argument" || pass
printf 'PASSWORD=Str0ng-and-long-enough\n' > "$pw"
(cmd_db_user_create --name=reporter --from="$pw" --dry-run) > /dev/null 2>&1 && fail "db:user:create refuses an existing user" || pass
(cmd_db_user_create --name=root --from="$pw" --dry-run) > /dev/null 2>&1 && fail "db:user:create refuses system users" || pass
(cmd_db_user_create --name=analyst --database=missing --from="$pw" --dry-run) > /dev/null 2>&1 && fail "db:user:create refuses unknown databases" || pass

out="$(cmd_db_create --name=metrics --user=reporter --dry-run 2>&1)"
[[ "$out" == *"CREATE DATABASE \`metrics\` CHARACTER SET utf8mb4"* && "$out" == *"GRANT ALL PRIVILEGES ON \`metrics\`.* TO 'reporter'@'localhost'"* ]] && pass || fail "db:create creates the database and grants the users"
(cmd_db_create --name=shop --dry-run) > /dev/null 2>&1 && fail "db:create refuses an existing database" || pass
(cmd_db_create --name=mysql --dry-run) > /dev/null 2>&1 && fail "db:create refuses system schemas" || pass
(cmd_db_create --name='bad;name' --dry-run) > /dev/null 2>&1 && fail "db:create validates the name" || pass

out="$(cmd_db_access --name=reports --user=shop --dry-run 2>&1)"
[[ "$out" == *"GRANT ALL PRIVILEGES ON \`reports\`.* TO 'shop'@'localhost'"* && "$out" == *"REVOKE ALL PRIVILEGES ON \`reports\`.* FROM 'reporter'@'localhost'"* ]] && pass || fail "db:access grants new users and revokes the rest"
(cmd_db_access --name=shop --user=reporter --dry-run) > /dev/null 2>&1 && fail "db:access keeps the app user on its database" || pass

out="$(cmd_db_user_update --name=reporter --database=reports --database=shop --dry-run 2>&1)"
[[ "$out" == *"GRANT ALL PRIVILEGES ON \`shop\`.* TO 'reporter'@'localhost'"* && "$out" == *"REVOKE ALL PRIVILEGES ON \`shop_archive\`.* FROM 'reporter'@'localhost'"* && "$out" != *"ALTER USER"* ]] && pass || fail "db:user:update sets exactly the databases given"
out="$(cmd_db_user_update --name=reporter --database=reports --from="$pw" --dry-run 2>&1)"
[[ "$out" == *"ALTER USER 'reporter'@'localhost' IDENTIFIED BY '***'"* ]] && pass || fail "db:user:update changes the password from a file"
(cmd_db_user_update --name=shop --database=shop --from="$pw" --dry-run) > /dev/null 2>&1 && fail "db:user:update leaves an app user's password to the app" || pass
(cmd_db_user_update --name=shop --database=reports --dry-run) > /dev/null 2>&1 && fail "db:user:update keeps an app user on its database" || pass
out="$(cmd_db_user_update --name=shop --database=shop --database=reports --dry-run 2>&1)"
[[ "$out" == *"GRANT ALL PRIVILEGES ON \`reports\`.* TO 'shop'@'localhost'"* ]] && pass || fail "db:user:update can give an app user more databases"

out="$(cmd_db_delete --name=reports --dry-run 2>&1)"
[[ "$out" == *"DROP DATABASE \`reports\`"* ]] && pass || fail "db:delete drops the database"
[[ "$out" == *"REVOKE ALL PRIVILEGES ON \`reports\`.* FROM 'reporter'@'localhost'"* ]] && pass || fail "db:delete removes the grants MariaDB would keep"
(cmd_db_delete --name=shop --dry-run) > /dev/null 2>&1 && fail "db:delete refuses a database an app uses" || pass
(cmd_db_delete --name=missing --dry-run) > /dev/null 2>&1 && fail "db:delete refuses an unknown database" || pass
out="$(cmd_db_user_delete --name=reporter --dry-run 2>&1)"
[[ "$out" == *"DROP USER 'reporter'@'localhost'"* ]] && pass || fail "db:user:delete drops the user"
(cmd_db_user_delete --name=shop --dry-run) > /dev/null 2>&1 && fail "db:user:delete refuses an app's user" || pass
unset MANDAL_FAKE_USERS

# ---------------------------------------------------------------------------
# updates
# ---------------------------------------------------------------------------

mkdir -p "$MANDAL_UPDATES_CACHE" "$MANDAL_BOOT_DIR"
printf '{"tag_name":"v9.9.9"}' > "$MANDAL_UPDATES_CACHE/github-dragonflydb-dragonfly.json"
printf '{"tag_name":"bun-v1.3.13"}' > "$MANDAL_UPDATES_CACHE/github-oven-sh-bun.json"
printf '{"stable":[{"path":"/download/2.9.7/composer.phar","version":"2.9.7"}]}' > "$MANDAL_UPDATES_CACHE/composer-latest.txt"
touch "$MANDAL_BOOT_DIR/vmlinuz-6.8.0-100-generic" "$MANDAL_BOOT_DIR/vmlinuz-6.8.0-147-generic"
printf '2026-10-27 06:00:01,123 INFO Starting unattended upgrades script\n2026-10-27 06:00:09,456 INFO All upgrades installed\n' > "$MANDAL_UNATTENDED_LOG"
printf 'Latest release: 28.04 LTS\n' > "$MANDAL_RELEASE_UPGRADES_CACHE"

assert_eq "updates_newer compares versions" "true false false" "$(updates_newer 1.2.3 1.10.0) $(updates_newer 2.0.0 1.9.9) $(updates_newer '' 1.0)"
pending="$(updates_pending_tsv)"
assert_eq "pending updates come from apt-get --just-print" "nginx	1.24.0-2ubuntu7.19	1.24.0-2ubuntu7.20	true
curl	8.5.0-2ubuntu10.1	8.5.0-2ubuntu10.2	false" "$pending"
status="$(updates_status_json)"
assert_json "status splits security from other updates" "$status" '(.pending.security | length | tostring) + "/" + (.pending.other | length | tostring)' "1/1"
assert_json "status lists held packages" "$status" '.pending.held | join(",")' "linux-image-generic"
assert_json "status finds the newest installed kernel" "$status" '.kernel.newest' "6.8.0-147-generic"
assert_json "status flags a newer cache release" "$status" '.components[] | select(.id == "cache") | .latest + "|" + (.update_available|tostring)' "9.9.9|false"
assert_json "status strips the bun tag prefix" "$status" '.components[] | select(.id == "js") | .latest' "1.3.13"
assert_json "status reads the composer release" "$status" '.components[] | select(.id == "composer") | .latest' "2.9.7"
assert_json "status reports the last unattended run" "$status" '.unattended.status + "|" + .unattended.last' "ok|2026-10-27 06:00:09"
assert_json "status reads apt history newest first" "$status" '.history[0].action + " " + .history[0].packages' "Upgrade nginx:amd64 (1.24.0-1, 1.24.0-2)"
assert_json "status mentions a newer LTS" "$status" '.release_upgrade' "28.04 LTS"
assert_json "status carries the reboot flag" "$status" '.reboot_required' "true"

apply="$(cmd_updates_apply --security --dry-run 2>&1)"
assert_eq "updates:apply --security dry run completes" "0" "$?"
[[ "$apply" == *"apt-get update"* && "$apply" == *"unattended-upgrade -v"* && "$apply" == *"systemctl reload-or-restart php8.5-fpm"* ]] && pass || fail "security updates refresh apt, run unattended-upgrade and reload PHP-FPM afterwards"
all="$(cmd_updates_apply --all --dry-run 2>&1)"
[[ "$all" == *"apt-get dist-upgrade"* && "$all" == *"apt-get autoremove"* ]] && pass || fail "updates:apply --all dist-upgrades and autoremoves"
one="$(cmd_updates_apply --package=curl --dry-run 2>&1)"
[[ "$one" == *"apt-get install --only-upgrade"*"curl"* ]] && pass || fail "updates:apply --package upgrades one package"
(cmd_updates_apply --package='curl; rm' --dry-run) > /dev/null 2>&1 && fail "updates:apply validates the package name" || pass
(cmd_updates_apply --dry-run) > /dev/null 2>&1 && fail "updates:apply needs a mode" || pass

comp="$(cmd_component_update --component=cache --dry-run 2>&1)"
[[ "$comp" == *"dragonfly_amd64.deb"* && "$comp" == *"systemctl restart dragonfly"* ]] && pass || fail "component:update cache reinstalls dragonfly"
comp="$(cmd_component_update --component=js --dry-run 2>&1)"
[[ "$comp" == *"bun-linux-x64.zip"* ]] && pass || fail "component:update js downloads bun"
(cmd_component_update --component=mandal --dry-run) > /dev/null 2>&1 && fail "component:update refuses unknown components" || pass
reboot_out="$(cmd_reboot --dry-run 2>&1)"
[[ "$reboot_out" == *"systemd-run --quiet --on-active=5"*"systemctl reboot"* ]] && pass || fail "reboot is scheduled a few seconds out"

# ---------------------------------------------------------------------------
# firewall and php management
# ---------------------------------------------------------------------------

fw="$(cmd_firewall_list)"
assert_json "firewall:list parses numbered rules" "$fw" '.rules | length' "6"
assert_json "firewall:list reads the rule parts" "$fw" '.rules[0] | (.number|tostring) + "|" + .to + "|" + .action + "|" + .from + "|" + .comment' "1|22/tcp|ALLOW IN|Anywhere|SSH"
assert_json "firewall:list marks v6 rules" "$fw" '.rules[] | select(.number == 4) | .ipv6' "true"
assert_json "firewall:list reads a source address" "$fw" '.rules[] | select(.number == 3) | .from' "10.0.0.0/8"

allow="$(cmd_firewall_allow --port=8443 --comment='Alt HTTPS' --dry-run 2>&1)"
[[ "$allow" == *"ufw allow 8443/tcp comment Alt HTTPS"* ]] && pass || fail "firewall:allow adds a port rule"
allow="$(cmd_firewall_allow --port=3306 --from=10.0.0.5 --dry-run 2>&1)"
[[ "$allow" == *"ufw allow from 10.0.0.5 to any port 3306 proto tcp"* ]] && pass || fail "firewall:allow restricts by source"
(cmd_firewall_allow --port='80; rm' --dry-run) > /dev/null 2>&1 && fail "firewall:allow validates the port" || pass
(cmd_firewall_allow --port=80 --from='evil$(x)' --dry-run) > /dev/null 2>&1 && fail "firewall:allow validates the source" || pass
(cmd_firewall_delete --number=1 --dry-run) > /dev/null 2>&1 && fail "firewall:delete refuses the SSH rule" || pass
del="$(cmd_firewall_delete --number=3 --dry-run 2>&1)"
[[ "$del" == *"ufw --force delete 3"* ]] && pass || fail "firewall:delete removes a rule by number"
(cmd_firewall_delete --number=abc --dry-run) > /dev/null 2>&1 && fail "firewall:delete validates the number" || pass

assert_json "php:available lists versions with completeness" "$(cmd_php_available)" 'map(select(.version == "8.6")) | .[0].complete' "false"
assert_json "php:list shows installed versions and their apps" "$(cmd_php_list)" 'map(select(.version == "8.5")) | .[0].apps | sort | join(",")' "api,shop"
install="$(cmd_php_install --version=8.4 --dry-run 2>&1)"
[[ "$install" == *"php8.4-fpm"* && "$install" == *"systemctl enable --now --quiet php8.4-fpm"* ]] && pass || fail "php:install installs the packages and starts fpm"
[[ "$install" == *"write ${MANDAL_PHP_DIR}/8.4/fpm/pool.d/00-mandal-global.conf"* ]] && pass || fail "php:install gives FPM a process_control_timeout so reloads finish requests in flight"
(cmd_php_remove --version=8.5 --dry-run) > /dev/null 2>&1 && fail "php:remove refuses a version apps use" || pass
remove="$(php_default_version() { echo 8.5; }; cmd_php_remove --version=8.3 --dry-run 2>&1)"
[[ "$remove" == *"apt-get remove --purge"*"php8.3-*"* ]] && pass || fail "php:remove purges an unused version"
default="$(cmd_php_default --version=8.5 --dry-run 2>&1)"
[[ "$default" == *"update-alternatives --set php /usr/bin/php8.5"* ]] && pass || fail "php:default switches the alternative"

# ---------------------------------------------------------------------------
# panel hosting
# ---------------------------------------------------------------------------

assert_eq "no panel app by default" "" "$(panel_apps)"
install="$(php_default_version() { echo 8.5; }; cmd_panel_install --domain=panel.example.com --repo=acme/mandal --https --dry-run 2>&1)"
assert_eq "panel:install dry run completes" "0" "$?"
[[ "$install" == *"Creating the panel app mandal-panel"* && "$install" == *"write ${MANDAL_HOME_DIR}/mandal-panel/shared/.env"* && "$install" == *"shared/database.sqlite"* && "$install" == *"write ${MANDAL_SUPERVISOR_DIR}/mandal-panel-queue.conf"* ]] && pass || fail "panel:install creates the app, its sqlite file and a queue worker"
[[ "$(panel_render_env mandal-panel panel.example.com)" == *"DB_CONNECTION=sqlite"*"DB_DATABASE=${MANDAL_HOME_DIR}/mandal-panel/shared/database.sqlite"*"QUEUE_CONNECTION=database"* ]] && pass || fail "the panel env uses sqlite in shared and the database queue"
(cmd_panel_install --repo=acme/mandal --dry-run) > /dev/null 2>&1 && fail "panel:install requires a domain" || pass

printf 'TYPE=laravel\nPANEL=true\nDOMAIN=panel.example.com\nPHP=8.5\n' > "${MANDAL_APPS_DIR}/mandal-panel.conf"
assert_eq "panel_apps finds the flagged app" "mandal-panel" "$(panel_apps)"
server_hosts_panel && pass || fail "server_hosts_panel is true with a panel app"
assert_json "inventory reports the panel app" "$(inv_mandal)" '(.hosts_panel|tostring) + "|" + .panel_app' "true|mandal-panel"
(cmd_app_delete --name=mandal-panel --dry-run) > /dev/null 2>&1 && fail "app:delete refuses the panel app" || pass
forced="$(MANDAL_FORCE_PANEL=1 cmd_app_delete --name=mandal-panel --dry-run 2>&1)"
[[ "$forced" == *"App mandal-panel removed."* ]] && pass || fail "app:delete can be forced on the panel app"
(cmd_firewall_delete --number=6 --dry-run) > /dev/null 2>&1 && fail "firewall:delete protects 443 while hosting the panel" || pass
(cmd_panel_install --name=other --domain=x.example.com --repo=acme/mandal --dry-run) > /dev/null 2>&1 && fail "panel:install refuses a second panel app under another name" || pass
again="$(cmd_panel_install --name=mandal-panel --domain=panel.example.com --repo=acme/mandal --dry-run 2>&1)"
assert_eq "panel:install succeeds when the panel app already exists" "0" "$?"
[[ "$again" == *"already exists; nothing to do"* && "$again" != *"Creating the panel app"* ]] && pass || fail "panel:install leaves an existing panel app alone"
printf 'TYPE=laravel\nPANEL=true\nDOMAIN=panel.example.com\nPHP=8.5\nREPO=acme/mandal\nBRANCH=v0.0.9\nCLONE=https\n' > "${MANDAL_APPS_DIR}/mandal-panel.conf"
pupdate="$(cmd_panel_update --source="${MANDAL_ROOT}/.." --dry-run 2>&1)"
assert_eq "panel:update dry run completes" "0" "$?"
for marker in "+ mandal $(mandal_version) already installed" "Updated ${MANDAL_APPS_DIR}/mandal-panel.conf." "Deploying mandal-panel from acme/mandal@v$(mandal_version)" "Mandal $(mandal_version) is installed and deployed."; do
    [[ "$pupdate" == *"$marker"* ]] && pass || fail "panel:update mentions: $marker"
done
[[ "$pupdate" != *"tar -xzf"* ]] && pass || fail "panel:update leaves a current CLI alone"
pold="$(mandal_version() { echo 0.0.9; }; cmd_panel_update --source="${MANDAL_ROOT}/.." --dry-run 2>&1)"
assert_eq "panel:update from an older CLI completes" "0" "$?"
[[ "$pold" == *"Installing the CLI from"* && "$pold" == *"tar -xzf"* ]] && pass || fail "panel:update updates an older CLI through cli:update"
mkdir -p "${MANDAL_HOME_DIR}/mandal-panel/current" && printf '%s\n' "$(mandal_version)" > "${MANDAL_HOME_DIR}/mandal-panel/current/VERSION"
assert_eq "panel:update is a no-op when installed and deployed" "Mandal $(mandal_version) is already installed and deployed." "$(cmd_panel_update --source="${MANDAL_ROOT}/.." --dry-run 2>&1)"
rm -rf "${MANDAL_HOME_DIR}/mandal-panel"
(cmd_panel_update --version=latest --dry-run) > /dev/null 2>&1 && fail "panel:update rejects a version that is not a tag" || pass
(cmd_panel_update --source="$TMP" --dry-run) > /dev/null 2>&1 && fail "panel:update rejects a source without the CLI" || pass
rm -f "${MANDAL_APPS_DIR}/mandal-panel.conf"
(cmd_panel_update --dry-run) > /dev/null 2>&1 && fail "panel:update refuses a server that does not host the panel" || pass

# ---------------------------------------------------------------------------
# setup.sh
# ---------------------------------------------------------------------------

# The script insists on root and shells out to useradd, chown and visudo, so
# it runs here with stand-ins for those, every path moved under $TMP and
# the CLI taken from this checkout instead of a release.
setup_bin="${TMP}/setup-bin"
mkdir -p "$setup_bin"
real_id="$(command -v id)"
printf '#!/usr/bin/env bash\n[[ "$1" == "-u" && $# -eq 1 ]] && { echo 0; exit 0; }\nexec %s "$@"\n' "$real_id" > "${setup_bin}/id"
printf '#!/usr/bin/env bash\nprintf "%%s\\n" "$*" >> "%s/useradd.log"\n' "$TMP" > "${setup_bin}/useradd"
printf '#!/usr/bin/env bash\nexit 0\n' > "${setup_bin}/chown"
printf '#!/usr/bin/env bash\nexit 0\n' > "${setup_bin}/visudo"
chmod +x "${setup_bin}"/*
setup_key="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1 mandal-panel"
setup_user="$("$real_id" -un)"
setup_root="${TMP}/setup"
run_setup() {
    PATH="${setup_bin}:${PATH}" MANDAL_HOME_DIR="${setup_root}/home" MANDAL_INSTALL_DIR="${setup_root}/opt/mandal" MANDAL_BIN_LINK="${setup_root}/bin/mandal" MANDAL_SUDOERS_FILE="${setup_root}/sudoers.d/mandal" \
        bash "${MANDAL_ROOT}/setup.sh" --source="${MANDAL_ROOT}/.." "$@" 2>&1
}

plan="$(run_setup --key="$setup_key" --user=fresh --dry-run)"
assert_eq "setup.sh dry run completes" "0" "$?"
for marker in "useradd --create-home --home-dir ${setup_root}/home/fresh --shell /bin/bash --user-group fresh" "append to ${setup_root}/home/fresh/.ssh/authorized_keys: ${setup_key}" "install ${MANDAL_ROOT}/.. ($(mandal_version)) -> ${setup_root}/opt/mandal" "write ${setup_root}/sudoers.d/mandal: fresh ALL=(root) NOPASSWD: ${setup_root}/opt/mandal/bin/mandal" "Dry run finished"; do
    [[ "$plan" == *"$marker"* ]] && pass || fail "setup.sh dry run mentions: $marker"
done
[[ ! -e "${setup_root}" ]] && pass || fail "setup.sh dry run changes nothing"

mkdir -p "${setup_root}/bin" # stands in for /usr/local/bin
first="$(run_setup --key="$setup_key" --user="$setup_user")"
assert_eq "setup.sh completes" "0" "$?"
[[ "$first" == *"user ${setup_user} already exists"* ]] && pass || fail "setup.sh keeps an existing user"
[[ ! -f "${TMP}/useradd.log" ]] && pass || fail "setup.sh does not call useradd for an existing user"
assert_eq "setup.sh installs the panel key" "$setup_key" "$(cat "${setup_root}/home/${setup_user}/.ssh/authorized_keys")"
assert_eq "setup.sh installs the CLI version" "$(mandal_version)" "$(cat "${setup_root}/opt/mandal/VERSION")"
[[ -x "${setup_root}/opt/mandal/bin/mandal" && -f "${setup_root}/opt/mandal/lib/core.sh" ]] && pass || fail "setup.sh installs the CLI files"
[[ ! -e "${setup_root}/opt/mandal/tests" ]] && pass || fail "setup.sh leaves the CLI tests out"
assert_eq "setup.sh links the CLI" "${setup_root}/opt/mandal/bin/mandal" "$(readlink "${setup_root}/bin/mandal")"
assert_eq "setup.sh writes the sudo rule" "${setup_user} ALL=(root) NOPASSWD: ${setup_root}/opt/mandal/bin/mandal" "$(cat "${setup_root}/sudoers.d/mandal")"
[[ "$first" == *"Done. mandal $(mandal_version) is installed"* ]] && pass || fail "setup.sh reports what it installed"

second="$(run_setup --key="$setup_key" --user="$setup_user")"
assert_eq "setup.sh runs again without error" "0" "$?"
for marker in "key already allowed" "mandal $(mandal_version) already installed" "sudoers.d/mandal unchanged"; do
    [[ "$second" == *"$marker"* ]] && pass || fail "setup.sh second run mentions: $marker"
done
assert_eq "setup.sh adds the key only once" "1" "$(wc -l < "${setup_root}/home/${setup_user}/.ssh/authorized_keys")"

printf '99.0.0\n' > "${setup_root}/opt/mandal/VERSION"
newer="$(run_setup --key="$setup_key" --user="$setup_user")"
[[ "$newer" == *"mandal 99.0.0 already installed"* ]] && pass || fail "setup.sh keeps a newer CLI"
assert_eq "setup.sh leaves the newer CLI in place" "99.0.0" "$(cat "${setup_root}/opt/mandal/VERSION")"
forced="$(run_setup --key="$setup_key" --user="$setup_user" --force)"
[[ "$forced" == *"install ${MANDAL_ROOT}/.."* ]] && pass || fail "setup.sh --force replaces a newer CLI"
assert_eq "setup.sh --force installs the requested version" "$(mandal_version)" "$(cat "${setup_root}/opt/mandal/VERSION")"
printf '0.0.1\n' > "${setup_root}/opt/mandal/VERSION"
older="$(run_setup --key="$setup_key" --user="$setup_user")"
[[ "$older" == *"install ${MANDAL_ROOT}/.."* ]] && pass || fail "setup.sh replaces an older CLI"
assert_eq "setup.sh upgrades an older CLI" "$(mandal_version)" "$(cat "${setup_root}/opt/mandal/VERSION")"

(run_setup --user="$setup_user" --dry-run) > /dev/null 2>&1 && fail "setup.sh requires --key" || pass
(run_setup --key="not a key" --dry-run) > /dev/null 2>&1 && fail "setup.sh rejects a malformed key" || pass
(run_setup --key="$setup_key" --user="Bad User" --dry-run) > /dev/null 2>&1 && fail "setup.sh rejects a bad user name" || pass
(run_setup --key="$setup_key" --version=latest --dry-run) > /dev/null 2>&1 && fail "setup.sh rejects a version that is not a tag" || pass
(run_setup --key="$setup_key" --repo='../etc' --dry-run) > /dev/null 2>&1 && fail "setup.sh rejects a bad repository" || pass
(run_setup --key="$setup_key" --bogus --dry-run) > /dev/null 2>&1 && fail "setup.sh rejects unknown options" || pass
(MANDAL_HOME_DIR="$TMP" bash "${MANDAL_ROOT}/setup.sh" --key="$setup_key" --source="$TMP" --dry-run) > /dev/null 2>&1 && fail "setup.sh rejects a source without the CLI" || pass
rm -rf "$setup_root"

# ---------------------------------------------------------------------------
# install.sh
# ---------------------------------------------------------------------------

# The installer drives the CLI and artisan; both are replaced by fakes that
# log their calls and leave behind the state the real commands would, so
# the script's decisions (what to run, what to skip) can be checked.
install_root="${TMP}/install"
install_bin="${TMP}/install-bin"
mkdir -p "$install_bin" "${install_root}/etc/apps" "${install_root}/bin" "${install_root}/certs"
cp "${setup_bin}/id" "${setup_bin}/chown" "$install_bin/"
cat > "${install_bin}/mandal" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$INSTALL_LOG/mandal.log"
manifest="$MANDAL_ETC/apps/mandal-panel.conf"
case "$1" in
    cli:update) tar -xzOf "${2#--from=}" ./VERSION > "$MANDAL_INSTALL_DIR/VERSION" ;;
    panel:update)
        for arg in "$@"; do case "$arg" in --version=*) v="${arg#*=}"; v="${v#v}" ;; esac; done
        echo "$v" > "$MANDAL_INSTALL_DIR/VERSION"
        sed -i "s|^BRANCH=.*|BRANCH=v$v|" "$manifest"
        mkdir -p "$MANDAL_HOME_DIR/mandal-panel/current" && echo "$v" > "$MANDAL_HOME_DIR/mandal-panel/current/VERSION"
        ;;
    provision) printf 'PROVISIONED_AT=2026-10-27T10:00:00Z\n' >> "$MANDAL_ETC/server.conf" ;;
    panel:install)
        if [[ -f "$manifest" ]]; then echo "Panel app mandal-panel already exists; nothing to do."; exit 0; fi
        for arg in "$@"; do case "$arg" in --domain=*) d="${arg#*=}" ;; --repo=*) r="${arg#*=}" ;; --branch=*) b="${arg#*=}" ;; esac; done
        printf 'TYPE=laravel\nPANEL=true\nDOMAIN=%s\nPHP=8.5\nREPO=%s\nBRANCH=%s\nCLONE=https\n' "$d" "$r" "$b" > "$manifest"
        ;;
    app:configure)
        for arg in "$@"; do case "$arg" in --branch=*) sed -i "s|^BRANCH=.*|BRANCH=${arg#*=}|" "$manifest" ;; --repo=*) sed -i "s|^REPO=.*|REPO=${arg#*=}|" "$manifest" ;; esac; done
        ;;
    app:deploy)
        mkdir -p "$MANDAL_HOME_DIR/mandal-panel/current"
        grep '^BRANCH=' "$manifest" | cut -d= -f2 | sed 's/^v//' > "$MANDAL_HOME_DIR/mandal-panel/current/VERSION"
        ;;
    app:ssl)
        [[ "${FAKE_SSL_FAILS:-0}" == 1 ]] && { echo "error: panel.example.com does not point at this server" >&2; exit 1; }
        mkdir -p "$MANDAL_LETSENCRYPT_DIR/panel.example.com" && touch "$MANDAL_LETSENCRYPT_DIR/panel.example.com/fullchain.pem"
        ;;
esac
EOF
cat > "${install_bin}/sudo" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$INSTALL_LOG/sudo.log"
if [[ "$*" == *"mandal:register-host"* ]]; then
    [[ "$*" == *"--verify"* ]] && touch "$INSTALL_LOG/connected"
    connected=false; [[ -f "$INSTALL_LOG/connected" ]] && connected=true
    printf '{"id":1,"name":"panel-host","public_key":"ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1 mandal panel (panel-host)","connected":%s}\n' "$connected"
fi
EOF
chmod +x "${install_bin}"/*
run_install() {
    PATH="${install_bin}:${PATH}" INSTALL_LOG="$install_root" MANDAL_ETC="${install_root}/etc" MANDAL_HOME_DIR="${install_root}/home" MANDAL_INSTALL_DIR="${install_root}/opt/mandal" MANDAL_BIN="${install_bin}/mandal" MANDAL_BIN_LINK="${install_root}/bin/mandal" MANDAL_LETSENCRYPT_DIR="${install_root}/certs" MANDAL_HOST_KEY_FILE="${install_root}/host_key.pub" \
        bash "${MANDAL_ROOT}/install.sh" --source="${MANDAL_ROOT}/.." "$@" 2>&1
}
mandal_calls() { cut -d' ' -f1 "${install_root}/mandal.log" | tr '\n' ' ' | sed 's/ $//'; }
clear_logs() { rm -f "${install_root}/mandal.log" "${install_root}/sudo.log"; }

(run_install) > /dev/null 2>&1 && fail "install.sh requires --domain the first time" || pass
(run_install --domain='bad domain') > /dev/null 2>&1 && fail "install.sh rejects a bad domain" || pass
(run_install --domain=panel.example.com --bogus) > /dev/null 2>&1 && fail "install.sh rejects unknown options" || pass
(run_install --domain=panel.example.com --version=latest) > /dev/null 2>&1 && fail "install.sh rejects a version that is not a tag" || pass

fresh="$(run_install --domain=panel.example.com --email=ops@example.com)"
assert_eq "install.sh completes on a fresh server" "0" "$?"
assert_eq "install.sh runs every step in order" "provision panel:install app:deploy app:ssl key:add" "$(mandal_calls)"
[[ "$(grep -c . "${install_root}/mandal.log")" -eq 5 ]] && pass || fail "install.sh runs each CLI step once"
grep -q "panel:install --name=mandal-panel --domain=panel.example.com --repo=ReyArlena/mandal --branch=v$(mandal_version) --https" "${install_root}/mandal.log" && pass || fail "install.sh pins the panel app to the release tag"
grep -q "app:ssl --name=mandal-panel --email=ops@example.com" "${install_root}/mandal.log" && pass || fail "install.sh passes the email to app:ssl"
grep -q "key:add --name=Hosted panel --key=ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1 mandal panel (panel-host)" "${install_root}/mandal.log" && pass || fail "install.sh allows the panel's own key"
artisan_calls() { awk '{for (i = 1; i <= NF; i++) if ($i ~ /artisan$/) c = $(i + 1); if ($0 ~ /--verify/) c = c " --verify"; print c}' "${install_root}/sudo.log" | tr '\n' '|' | sed 's/|$//'; }
assert_eq "install.sh seeds the first user and registers the host twice" "db:seed|mandal:register-host|mandal:register-host --verify" "$(artisan_calls)"
grep -q "^-u mandal-panel -H /usr/bin/php8.5 ${install_root}/home/mandal-panel/current/artisan db:seed" "${install_root}/sudo.log" && pass || fail "install.sh runs artisan as the app user with the app's PHP"
assert_eq "install.sh installs the CLI" "$(mandal_version)" "$(cat "${install_root}/opt/mandal/VERSION")"
assert_eq "install.sh links the CLI" "${install_root}/opt/mandal/bin/mandal" "$(readlink "${install_root}/bin/mandal")"
[[ "$fresh" == *"Mandal $(mandal_version) is installed at https://panel.example.com"* ]] && pass || fail "install.sh reports where the panel is"

clear_logs
again="$(run_install)"
assert_eq "install.sh runs again without --domain" "0" "$?"
assert_eq "install.sh hands an installed panel to panel:update" "panel:update key:add" "$(mandal_calls)"
grep -q "panel:update --version=v$(mandal_version) --source=${MANDAL_ROOT}/.." "${install_root}/mandal.log" && pass || fail "install.sh passes the release and source to panel:update"
for marker in "certificate for panel.example.com already present" "already connected"; do
    [[ "$again" == *"$marker"* ]] && pass || fail "install.sh second run mentions: $marker"
done
[[ "$(grep -c 'register-host' "${install_root}/sudo.log")" -eq 1 ]] && pass || fail "install.sh does not verify a connected host again"
(run_install --domain=other.example.com) > /dev/null 2>&1 && fail "install.sh refuses to move the panel to another domain" || pass

clear_logs
printf '0.0.9\n' > "${install_root}/opt/mandal/VERSION"
printf '0.0.9\n' > "${install_root}/home/mandal-panel/current/VERSION"
sed -i 's/^BRANCH=.*/BRANCH=v0.0.9/' "${install_root}/etc/apps/mandal-panel.conf"
update="$(run_install)"
assert_eq "install.sh updates an older install" "0" "$?"
assert_eq "install.sh updates an installed panel through panel:update only" "panel:update key:add" "$(mandal_calls)"
assert_eq "install.sh ends with the CLI at the release" "$(mandal_version)" "$(cat "${install_root}/opt/mandal/VERSION")"
assert_eq "install.sh ends with the panel app on the release tag" "v$(mandal_version)" "$(grep '^BRANCH=' "${install_root}/etc/apps/mandal-panel.conf" | cut -d= -f2)"
assert_eq "install.sh deploys the new release" "$(mandal_version)" "$(cat "${install_root}/home/mandal-panel/current/VERSION")"

clear_logs
rm -rf "${install_root}/certs/panel.example.com"
failed_ssl="$(FAKE_SSL_FAILS=1 run_install)"
assert_eq "install.sh carries on when the certificate cannot be issued" "0" "$?"
[[ "$failed_ssl" == *"warning: no certificate yet. Point panel.example.com at this server and run this script again."* ]] && pass || fail "install.sh explains a missing certificate"
clear_logs
skipped_ssl="$(run_install --skip-ssl)"
[[ "$skipped_ssl" == *"skipped (--skip-ssl)"* && "$(mandal_calls)" != *"app:ssl"* ]] && pass || fail "install.sh honours --skip-ssl"

printf 'TYPE=laravel\nDOMAIN=x.example.com\nPHP=8.5\n' > "${install_root}/etc/apps/mandal-panel.conf"
(run_install) > /dev/null 2>&1 && fail "install.sh refuses an app of the same name that is not the panel" || pass
rm -rf "$install_root" "$install_bin"

# ---------------------------------------------------------------------------
# docker apps
# ---------------------------------------------------------------------------

export MANDAL_DOCKER_DIR="${TMP}/srv/docker"
proxy="$(docker_render_proxy_vhost tool redirect-to-naked 20000 tool.example.com)"
[[ "$proxy" == *"proxy_pass http://127.0.0.1:20000;"* && "$proxy" == *"server_name www.tool.example.com;"* && "$proxy" == *'proxy_set_header Upgrade $http_upgrade;'* ]] && pass || fail "proxy vhost forwards to the container and handles websockets"
assert_eq "every server block in the proxy vhost hides the nginx version" "2" "$(grep -c 'server_tokens off;' <<< "$proxy")"
(validate_env_pair 'bad key=1') 2> /dev/null && fail "validate_env_pair rejects spaces in keys" || pass
(validate_proxy_port 99999) 2> /dev/null && fail "validate_proxy_port rejects ports above 65535" || pass

# A fake docker: `compose config` answers with the ports from an env var
# (or fails on demand), `ps` with the containers from another.
mkdir -p "${TMP}/compose-bin"
cat > "${TMP}/compose-bin/docker" <<'DOCKEREOF'
#!/usr/bin/env bash
if [[ "$*" == *"config --format json"* ]]; then
    [[ "${MANDAL_FAKE_COMPOSE_FAIL:-0}" == 1 ]] && { echo "services.app.ports must be a list" >&2; exit 1; }
    printf '{"services":{"app":{"image":"x","ports":%s}}}\n' "${MANDAL_FAKE_COMPOSE_PORTS:-[]}"
elif [[ "$1" == "ps" ]]; then
    printf '%s\n' "${MANDAL_FAKE_DOCKER_PS:-}"
fi
DOCKEREOF
chmod +x "${TMP}/compose-bin/docker"
compose_file="${TMP}/tool-compose.yml"
printf 'services:\n  app:\n    image: ghcr.io/org/tool:1.2\n    ports:\n      - "127.0.0.1:20000:8080"\n' > "$compose_file"

dcreate="$(cmd_app_create --name=tool --type=docker --from="$compose_file" --proxy --port=20000 --domain=tool.example.com --env=APP_KEY=abc --env=DEBUG=0 --dry-run 2>&1)"
assert_eq "docker app:create dry run completes" "0" "$?"
for marker in "write ${MANDAL_DOCKER_DIR}/tool/compose.yml (0640" "write ${MANDAL_DOCKER_DIR}/tool/.env (0600" "write ${MANDAL_NGINX_DIR}/conf.d/mandal-upgrade.conf" "write ${MANDAL_NGINX_DIR}/sites-available/tool.conf" "docker compose --progress plain --project-directory ${MANDAL_DOCKER_DIR}/tool --project-name tool pull" "--project-name tool up -d --remove-orphans" "Docker app tool created; nginx serves tool.example.com from 127.0.0.1:20000."; do
    if [[ "$dcreate" == *"$marker"* ]]; then pass; else fail "docker app:create mentions: $marker"; fi
done
[[ "$dcreate" != *"useradd"* && "$dcreate" != *"CREATE DATABASE"* ]] && pass || fail "docker app:create creates no user or database"
[[ -f "$compose_file" ]] && pass || fail "docker app:create dry run keeps the uploaded compose file"
dgame="$(cmd_app_create --name=game --type=docker --from="$compose_file" --domain=ignored.example.com --dry-run 2>&1)"
assert_eq "docker app:create without a proxy completes" "0" "$?"
[[ "$dgame" != *"sites-available"* && "$dgame" != *"mandal-upgrade.conf"* && "$dgame" == *"published directly and bypass the firewall"* ]] && pass || fail "docker app:create without --proxy writes no vhost and warns about the firewall"
(cmd_app_create --name=tool --type=docker --domain=tool.example.com --dry-run) > /dev/null 2>&1 && fail "docker app:create requires a compose file" || pass
(cmd_app_create --name=tool --type=docker --from="$compose_file" --proxy --domain=tool.example.com --dry-run) > /dev/null 2>&1 && fail "docker app:create with --proxy requires a port" || pass
(cmd_app_create --name=tool --type=docker --from="$compose_file" --proxy --port=20000 --dry-run) > /dev/null 2>&1 && fail "docker app:create with --proxy requires a domain" || pass
(cmd_app_create --name=tool --type=docker --from="$compose_file" --proxy --port=99999 --domain=tool.example.com --dry-run) > /dev/null 2>&1 && fail "docker app:create validates the proxy port" || pass
: > "${TMP}/empty.yml"
(cmd_app_create --name=tool --type=docker --from="${TMP}/empty.yml" --dry-run) > /dev/null 2>&1 && fail "docker app:create rejects an empty compose file" || pass

(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[]' docker_app_create webx "$compose_file" true 20200 naked-only webx.example.com "") > /dev/null 2>&1 && fail "docker_app_create fails when the proxied port is not published" || pass
[[ ! -e "${MANDAL_DOCKER_DIR}/webx" ]] && pass || fail "docker_app_create removes the project directory after a failed check"

mkdir -p "${MANDAL_DOCKER_DIR}/tool"
cp "$compose_file" "${MANDAL_DOCKER_DIR}/tool/compose.yml"
(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[{"host_ip":"127.0.0.1","published":"20000","target":8080,"protocol":"tcp"}]' docker_check_project tool true 20000) > /dev/null 2>&1 && pass || fail "docker_check_project accepts a service on the proxied loopback port"
(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[{"published":"15637","target":15637,"protocol":"udp"}]' docker_check_project tool true 20000) > /dev/null 2>&1 && fail "docker_check_project refuses a proxy without the loopback port" || pass
(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[{"published":"15637","target":15637,"protocol":"udp"}]' docker_check_project tool false "") > /dev/null 2>&1 && pass || fail "docker_check_project accepts any ports without a proxy"
(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_FAIL=1 docker_check_project tool false "") > /dev/null 2>&1 && fail "docker_check_project refuses an invalid compose file" || pass
assert_json "docker_app_ports_json lists the published ports" "$(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[{"host_ip":"127.0.0.1","published":"20000","target":8080},{"published":"15637","target":15637,"protocol":"udp"}]' docker_app_ports_json tool)" 'map(.host_ip + ":" + .published + "->" + .target + "/" + .protocol) | join(",")' "127.0.0.1:20000->8080/tcp,0.0.0.0:15637->15637/udp"

ps_json='{"Names":"enshrouded-server","Ports":"0.0.0.0:15637->15637/udp, :::15637->15637/udp"}
{"Names":"tool-app-1","Ports":"127.0.0.1:20000->8080/tcp"}
{"Names":"valheim-server","Ports":"0.0.0.0:2456-2457->2456-2457/udp, [::]:2456-2457->2456-2457/udp"}'
exposed="$(unit_state() { echo active; }; PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_DOCKER_PS="$ps_json" docker_exposed_ports_json)"
assert_json "exposed ports skip loopback bindings and duplicates per protocol" "$exposed" 'map(.container + ":" + .port + "/" + .protocol) | join(",")' "enshrouded-server:15637/udp,valheim-server:2456-2457/udp"
assert_json "exposed ports are empty while docker is inactive" "$(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_DOCKER_PS="$ps_json" docker_exposed_ports_json)" 'length' "0"
assert_json "firewall:list reports docker" "$(PATH="${TMP}/compose-bin:${PATH}" firewall_rules_json)" '.docker | (.active | tostring) + ":" + (.exposed | length | tostring)' "false:0"

printf 'TYPE=docker\nDOMAIN=tool.example.com\nWWW=redirect-to-naked\nPROXY=true\nPORT=20000\n' > "${MANDAL_APPS_DIR}/tool.conf"
printf 'services:\n  app:\n    image: ghcr.io/org/tool:1.3\n' > "${TMP}/new-compose.yml"
dcompose="$(cmd_app_compose --name=tool 2>&1)"
[[ "$dcompose" == *"ghcr.io/org/tool:1.2"* ]] && pass || fail "app:compose prints the compose file"
dreplace="$(cmd_app_compose --name=tool --from="${TMP}/new-compose.yml" --dry-run 2>&1)"
assert_eq "app:compose dry run completes" "0" "$?"
[[ "$dreplace" == *"write ${MANDAL_DOCKER_DIR}/tool/compose.yml"* && "$dreplace" == *"up -d --remove-orphans"* ]] && pass || fail "app:compose writes the file and recreates the containers"
dinvalid="$(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_FAIL=1 cmd_app_compose --name=tool --from="${TMP}/new-compose.yml" 2>&1)"
assert_eq "app:compose refuses an invalid file" "1" "$?"
[[ "$dinvalid" == *"previous compose file was kept"* ]] && pass || fail "app:compose says it kept the old file"
[[ "$(cat "${MANDAL_DOCKER_DIR}/tool/compose.yml")" == "$(cat "$compose_file")" ]] && pass || fail "app:compose restores the previous file after a failed check"
[[ -f "${TMP}/new-compose.yml" ]] && pass || fail "app:compose leaves the upload for the panel to remove when it refuses it"
mkdir -p "${MANDAL_NGINX_DIR}/sites-available"
docker_render_proxy_vhost tool redirect-to-naked 20000 tool.example.com > "${MANDAL_NGINX_DIR}/sites-available/tool.conf"
printf 'services:\n  app:\n    image: ghcr.io/org/tool:1.4\n    ports:\n      - "127.0.0.1:20005:8080"\n' > "${TMP}/moved-compose.yml"
dmoved="$(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[{"host_ip":"127.0.0.1","published":"20005","target":8080}]' cmd_app_compose --name=tool --from="${TMP}/moved-compose.yml" 2>&1)"
assert_eq "app:compose follows a moved loopback port" "0" "$?"
[[ "$dmoved" == *"nginx now proxies tool to 127.0.0.1:20005."* ]] && pass || fail "app:compose says it followed the port"
assert_eq "app:compose records the followed port" "20005" "$(conf_get "${MANDAL_APPS_DIR}/tool.conf" PORT)"
grep -q 'proxy_pass http://127.0.0.1:20005;' "${MANDAL_NGINX_DIR}/sites-available/tool.conf" && pass || fail "app:compose points nginx at the followed port"
printf 'services:\n  app:\n    image: ghcr.io/org/tool:1.5\n    ports:\n      - "127.0.0.1:20006:8080"\n      - "127.0.0.1:20007:9090"\n' > "${TMP}/ambiguous-compose.yml"
dambig="$(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[{"host_ip":"127.0.0.1","published":"20006","target":8080},{"host_ip":"127.0.0.1","published":"20007","target":9090}]' cmd_app_compose --name=tool --from="${TMP}/ambiguous-compose.yml" 2>&1)"
assert_eq "app:compose refuses when several loopback ports could be the upstream" "1" "$?"
[[ "$dambig" == *"Please change the port using the Upstream port button"* ]] && pass || fail "app:compose points at the Upstream port button"
assert_eq "an ambiguous file leaves the port alone" "20005" "$(conf_get "${MANDAL_APPS_DIR}/tool.conf" PORT)"
grep -q 'tool:1.4' "${MANDAL_DOCKER_DIR}/tool/compose.yml" && pass || fail "an ambiguous file is not applied"
rm -f "${MANDAL_NGINX_DIR}/sites-available/tool.conf"
printf 'TYPE=docker\nDOMAIN=tool.example.com\nWWW=redirect-to-naked\nPROXY=true\nPORT=20000\n' > "${MANDAL_APPS_DIR}/tool.conf"
cp "$compose_file" "${MANDAL_DOCKER_DIR}/tool/compose.yml"
for action in start stop restart; do
    dcontrol="$(cmd_app_control --name=tool --action=$action --dry-run 2>&1)"
    assert_eq "app:control $action dry run completes" "0" "$?"
    case "$action" in
        start) marker="--project-name tool up -d" ;;
        stop) marker="--project-name tool stop" ;;
        restart) marker="--project-name tool restart" ;;
    esac
    [[ "$dcontrol" == *"$marker"* && "$dcontrol" == *"--project-name tool ps"* && "$dcontrol" == *"tool: $action done."* ]] && pass || fail "app:control $action runs compose $action and shows the containers"
done
[[ "$(cmd_app_control --name=tool --action=stop --dry-run 2>&1)" != *"--project-name tool down"* ]] && pass || fail "app:control stop keeps the containers (no compose down)"
(cmd_app_control --name=tool --action=kill --dry-run) > /dev/null 2>&1 && fail "app:control rejects unknown actions" || pass
(cmd_app_control --name=tool --dry-run) > /dev/null 2>&1 && fail "app:control requires an action" || pass
printf 'TYPE=laravel\n' > "${MANDAL_APPS_DIR}/notdocker.conf"
(cmd_app_control --name=notdocker --action=start --dry-run) > /dev/null 2>&1 && fail "app:control refuses a Laravel app" || pass
(cmd_app_compose --name=notdocker) > /dev/null 2>&1 && fail "app:compose refuses a Laravel app" || pass
(cmd_app_configure --name=notdocker --port=20001 --dry-run) > /dev/null 2>&1 && fail "app:configure --port refuses a Laravel app" || pass
rm -f "${MANDAL_APPS_DIR}/notdocker.conf"

mkdir -p "${MANDAL_NGINX_DIR}/sites-available"
docker_render_proxy_vhost tool redirect-to-naked 20000 tool.example.com > "${MANDAL_NGINX_DIR}/sites-available/tool.conf"
printf '    ssl_certificate /etc/letsencrypt/live/tool.example.com/fullchain.pem; # managed by Certbot\n' >> "${MANDAL_NGINX_DIR}/sites-available/tool.conf"
(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[{"host_ip":"127.0.0.1","published":"20001","target":8080}]' cmd_app_configure --name=tool --port=20001) > /dev/null 2>&1
assert_eq "app:configure --port completes" "0" "$?"
assert_eq "app:configure --port updates the manifest" "20001" "$(conf_get "${MANDAL_APPS_DIR}/tool.conf" PORT)"
grep -q 'proxy_pass http://127.0.0.1:20001;' "${MANDAL_NGINX_DIR}/sites-available/tool.conf" && pass || fail "app:configure --port rewrites proxy_pass"
grep -q 'managed by Certbot' "${MANDAL_NGINX_DIR}/sites-available/tool.conf" && pass || fail "app:configure --port keeps certbot's lines"
(PATH="${TMP}/compose-bin:${PATH}" MANDAL_FAKE_COMPOSE_PORTS='[{"host_ip":"127.0.0.1","published":"20001","target":8080}]' cmd_app_configure --name=tool --port=20002) > /dev/null 2>&1 && fail "app:configure --port refuses a port the compose file does not publish" || pass
assert_eq "a refused port leaves the manifest alone" "20001" "$(conf_get "${MANDAL_APPS_DIR}/tool.conf" PORT)"
(cmd_app_configure --name=tool --port=99999 --dry-run) > /dev/null 2>&1 && fail "app:configure --port validates the port" || pass
printf 'TYPE=docker\nPROXY=false\n' > "${MANDAL_APPS_DIR}/game.conf"
(cmd_app_configure --name=game --port=20001 --dry-run) > /dev/null 2>&1 && fail "app:configure --port refuses an app without the proxy" || pass
rm -f "${MANDAL_APPS_DIR}/game.conf" "${MANDAL_NGINX_DIR}/sites-available/tool.conf"
printf 'TYPE=docker\nDOMAIN=tool.example.com\nWWW=redirect-to-naked\nPROXY=true\nPORT=20000\n' > "${MANDAL_APPS_DIR}/tool.conf"
ddeploy="$(cmd_app_deploy --name=tool --dry-run 2>&1)"
[[ "$ddeploy" == *"--project-name tool pull"* && "$ddeploy" == *"up -d --remove-orphans"* && "$ddeploy" != *"git clone"* ]] && pass || fail "docker app:deploy pulls and recreates instead of cloning"
ddelete="$(cmd_app_delete --name=tool --dry-run 2>&1)"
[[ "$ddelete" == *"down --volumes --remove-orphans"* && "$ddelete" == *"rm -rf ${MANDAL_DOCKER_DIR}/tool"* && "$ddelete" == *"rm -f ${MANDAL_NGINX_DIR}/sites-enabled/tool.conf"* ]] && pass || fail "docker app:delete removes containers, volumes, files and the vhost"
dkeep="$(cmd_app_delete --name=tool --keep-files --dry-run 2>&1)"
[[ "$dkeep" == *"down --remove-orphans"* && "$dkeep" != *"--volumes"* ]] && pass || fail "docker app:delete can keep the volumes"
printf 'A=1\n' > "${TMP}/tool.env"
denv="$(cmd_app_env --name=tool --from="${TMP}/tool.env" --dry-run 2>&1)"
[[ "$denv" == *"write ${MANDAL_DOCKER_DIR}/tool/.env (0600 root:root)"* ]] && pass || fail "app:env targets the docker app's env file" "$(printf '%s' "$denv" | tail -n 3)"
assert_json "inventory marks docker apps with their proxy port" "$(inv_apps)" 'map(select(.name == "tool")) | .[0] | .type + ":" + (.proxy | tostring) + ":" + .port + ":" + (.ports | length | tostring)' "docker:true:20000:0"
rm -f "${MANDAL_APPS_DIR}/tool.conf"

# ---------------------------------------------------------------------------
# panel keys
# ---------------------------------------------------------------------------

export MANDAL_SSH_USER=mandal
mkdir -p "${MANDAL_HOME_DIR}/mandal/.ssh"
key1="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ7VGvy2x0pt4Km9vJg5oY5Z4L0mB6x2F6F8pQ3n3Kp1 mandal-panel: first"
printf '%s\n' "$key1" > "$(key_authorized_file)"
[[ "$(key_fingerprint "$key1")" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] && pass || fail "key_fingerprint looks like ssh-keygen output"
assert_json "key:list reads labelled keys" "$(cmd_key_list)" '.[0].comment + "|" + .[0].type' "mandal-panel: first|ssh-ed25519"
key2="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGVsc2VzaG9ydHNob3J0c2hvcnRzaG9ydHNob3J0c2hvcg== laptop"
export MANDAL_SUDOERS_FILE="${TMP}/key-sudoers/mandal"
add="$(PATH="${setup_bin}:${PATH}" cmd_key_add --key="$key2" --name='Laptop panel' 2>&1)"
assert_eq "key:add completes" "0" "$?"
[[ "$add" == *"useradd --create-home --home-dir ${MANDAL_HOME_DIR}/mandal --shell /bin/bash --user-group mandal"* ]] && pass || fail "key:add creates the SSH user when it is missing"
assert_eq "key:add writes the sudo rule" "mandal ALL=(root) NOPASSWD: ${MANDAL_ROOT}/bin/mandal" "$(cat "$MANDAL_SUDOERS_FILE")"
assert_eq "key:add keeps the sudo rule file private" "440" "$(stat -c %a "$MANDAL_SUDOERS_FILE")"
assert_eq "key:add appends a labelled line" "1" "$(grep -c 'mandal-panel: Laptop panel$' "$(key_authorized_file)")"
assert_eq "key:add is idempotent" "This key is already allowed." "$(cmd_key_add --key="$key2" --name='Laptop panel' 2>&1)"
(cmd_key_add --key='ssh-ed25519 not*base64 x' --name=a) > /dev/null 2>&1 && fail "key:add validates the key" || pass
(cmd_key_add --key="$key2" --name='bad;name') > /dev/null 2>&1 && fail "key:add validates the name" || pass
key3="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHRoaXJkdGhpcmR0aGlyZHRoaXJkdGhpcmR0aGlyZHRoaXJkdGhpcmQ= third"
again="$(id() { [[ "$1" == mandal ]] || command id "$@"; }; PATH="${setup_bin}:${PATH}" cmd_key_add --key="$key3" --name='Third' 2>&1)"
assert_eq "key:add completes when the user exists" "0" "$?"
[[ "$again" != *"useradd"* && "$again" != *"write ${MANDAL_SUDOERS_FILE}"* ]] && pass || fail "key:add leaves an existing user and sudo rule alone"
(cmd_key_remove --fingerprint="$(key_fingerprint "$key3")") > /dev/null 2>&1
fp2="$(key_fingerprint "$key2")"
(cmd_key_remove --fingerprint="$fp2") > /dev/null 2>&1
assert_eq "key:remove drops the key" "0" "$(grep -c 'Laptop panel' "$(key_authorized_file)")"
(cmd_key_remove --fingerprint="$(key_fingerprint "$key1")") > /dev/null 2>&1 && fail "key:remove refuses the last key" || pass
rm -rf "${MANDAL_HOME_DIR}/mandal"

# panel sqlite in backups
printf 'TYPE=laravel\nPANEL=true\nDOMAIN=panel.example.com\nPHP=8.5\n' > "${MANDAL_APPS_DIR}/mandal-panel.conf"
mkdir -p "${MANDAL_HOME_DIR}/mandal-panel/shared" && printf 'x' > "${MANDAL_HOME_DIR}/mandal-panel/shared/database.sqlite"
sqlite_run="$(MANDAL_FAKE_DBS=$'mysql\nshop' MANDAL_DRY_RUN=1 backup_run 2>&1)"
[[ "$sqlite_run" == *"copy ${MANDAL_HOME_DIR}/mandal-panel/shared/database.sqlite -> ${MANDAL_BACKUP_DIR}/mandal-panel-sqlite/mandal-panel-sqlite-"* && "$sqlite_run" == *"ok mandal-panel-sqlite"* ]] && pass || fail "backup:run copies the panel's sqlite file"
assert_json "backup:list includes the panel sqlite set" "$(MANDAL_FAKE_DBS=$'mysql\nshop' backup_list_json)" 'map(.database) | join(",")' "shop,mandal-panel-sqlite"
rm -f "${MANDAL_APPS_DIR}/mandal-panel.conf"; rm -rf "${MANDAL_HOME_DIR}/mandal-panel"

MANDAL_OS_RELEASE="${FIXTURES}/os-release-debian" bash -c 'source "'"${MANDAL_ROOT}"'/lib/core.sh"; mandal_load_commands; load_all_drivers; is_root(){ return 0; }; MANDAL_DRY_RUN=1 provision_apply' > /dev/null 2>&1
assert_eq "provisioning refuses an unsupported OS" "1" "$?"

# ---------------------------------------------------------------------------

printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
[[ "$FAILED" -eq 0 ]]
