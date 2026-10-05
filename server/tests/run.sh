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
mkdir -p "${MANDAL_APT_SOURCES_DIR}"

# Only the fakes and a fixed set of real tools are on PATH, so whatever the
# host has installed (bun, docker, nginx...) cannot leak into the results.
mkdir -p "${TMP}/bin"
for tool in bash sh jq awk grep sed tr cut find wc basename dirname readlink id hostname uname nproc df mktemp mv chmod chown cat date sort uniq head tail tee ls touch base64 sha256sum tar gzip getent stat flock curl printf mkdir cp rm ln env; do
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
assert_json "check accepts Ubuntu 24.04" "$check" '.os.supported' "true"
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
for marker in "==> Deploying shop from acme/shop@main" "git clone --quiet --depth 1 --branch main git@github.com:acme/shop.git ${MANDAL_HOME_DIR}/shop/releases/" "GIT_SSH_COMMAND=ssh -i ${MANDAL_HOME_DIR}/shop/.ssh/id_ed25519" "ln -sfn ${MANDAL_HOME_DIR}/shop/shared/storage" "ln -sfn ${MANDAL_HOME_DIR}/shop/shared/.env" "exec bash '${MANDAL_HOME_DIR}/shop/deploy.sh'" "PHP=/usr/bin/php8.5" "mv -T ${MANDAL_HOME_DIR}/shop/current.new ${MANDAL_HOME_DIR}/shop/current" "systemctl reload-or-restart php8.5-fpm" "artisan queue:restart" "==> Pruning old releases (keeping 5)" "Deployed shop"; do
    if [[ "$deploy" == *"$marker"* ]]; then pass; else fail "app:deploy mentions: $marker"; fi
done
[[ "$deploy" == *"sudo -u shop -H env HOME=${MANDAL_HOME_DIR}/shop"* ]] && pass || fail "deploy steps run as the app user"
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
cmd_backup_configure --schedule='30 2 * * *' --keep-local=2 --keep-remote=5 --no-remote > /dev/null 2>&1
assert_json "backup status reflects the configuration" "$(backup_status_json)" '.schedule + "|" + (.keep_local|tostring) + "|" + (.remote.enabled|tostring)' "30 2 * * *|2|false"
mkdir -p "${MANDAL_BACKUP_DIR}/shop"
for ts in 20261020020000 20261021020000 20261022020000; do printf 'x' > "${MANDAL_BACKUP_DIR}/shop/shop-${ts}.sql.zst"; done
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
(cmd_logs_read --source=nginx:shop:access --lines=99999) > /dev/null 2>&1 && fail "logs:read caps the line count" || pass
(cmd_logs_read --source=nginx:shop:access --since=yesterday) > /dev/null 2>&1 && fail "logs:read validates --since" || pass

# ---------------------------------------------------------------------------
# updates
# ---------------------------------------------------------------------------

mkdir -p "$MANDAL_UPDATES_CACHE" "$MANDAL_BOOT_DIR"
printf '{"tag_name":"v9.9.9"}' > "$MANDAL_UPDATES_CACHE/github-dragonflydb-dragonfly.json"
printf '{"tag_name":"bun-v1.3.13"}' > "$MANDAL_UPDATES_CACHE/github-oven-sh-bun.json"
printf '{"stable":[{"path":"/download/2.9.7/composer.phar","version":"2.9.7"}]}' > "$MANDAL_UPDATES_CACHE/composer-latest.txt"
touch "$MANDAL_BOOT_DIR/vmlinuz-6.8.0-100-generic" "$MANDAL_BOOT_DIR/vmlinuz-6.8.0-147-generic"
printf '2026-10-27 06:00:01,123 INFO Starting unattended upgrades script\n2026-10-27 06:00:09,456 INFO All upgrades installed\n' > "$MANDAL_UNATTENDED_LOG"
printf 'Latest release: 26.04 LTS\n' > "$MANDAL_RELEASE_UPGRADES_CACHE"

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
assert_json "status mentions a newer LTS" "$status" '.release_upgrade' "26.04 LTS"
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
(cmd_panel_install --domain=x.example.com --repo=acme/mandal --dry-run) > /dev/null 2>&1 && fail "panel:install refuses a second panel app" || pass
rm -f "${MANDAL_APPS_DIR}/mandal-panel.conf"

MANDAL_OS_RELEASE="${FIXTURES}/os-release-debian" bash -c 'source "'"${MANDAL_ROOT}"'/lib/core.sh"; mandal_load_commands; load_all_drivers; is_root(){ return 0; }; MANDAL_DRY_RUN=1 provision_apply' > /dev/null 2>&1
assert_eq "provisioning refuses an unsupported OS" "1" "$?"

# ---------------------------------------------------------------------------

printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
[[ "$FAILED" -eq 0 ]]
