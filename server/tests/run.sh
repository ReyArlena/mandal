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
mkdir -p "${MANDAL_APT_SOURCES_DIR}"

# Only the fakes and a fixed set of real tools are on PATH, so whatever the
# host has installed (bun, docker, nginx...) cannot leak into the results.
mkdir -p "${TMP}/bin"
for tool in bash sh jq awk grep sed tr cut find wc basename dirname readlink id hostname uname nproc df mktemp mv chmod chown cat date sort uniq head tail tee ls touch base64 flock printf mkdir cp rm ln env; do
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
assert_json "whole inventory is valid JSON under pipefail" "$(cmd_inventory)" 'keys | length' "14"
set +o pipefail

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
(validate_www_mode naked-only) && pass || fail "validate_www_mode accepts naked-only"
(validate_www_mode both) 2> /dev/null && fail "validate_www_mode rejects unknown modes" || pass

create="$(php_default_version() { echo 8.5; }; cmd_app_create --name=blog --domain=blog.example.com --alias=blog.example.org --repo=acme/blog --dry-run 2>&1)"
create_status=$?
assert_eq "app:create dry run completes" "0" "$create_status"
[[ "$create_status" -eq 0 ]] || printf '%s\n' "$create" | tail -n 6 | sed 's/^/      | /'
for marker in "useradd --create-home --home-dir ${MANDAL_HOME_DIR}/blog" "chmod 0750 ${MANDAL_HOME_DIR}/blog" "setfacl -m u:www-data:x" "ssh-keygen" "write ${MANDAL_PHP_DIR}/8.5/fpm/pool.d/blog.conf" "write ${MANDAL_NGINX_DIR}/sites-available/blog.conf" "sites-enabled/blog.conf" "CREATE DATABASE IF NOT EXISTS \`blog\`" "GRANT ALL PRIVILEGES ON \`blog\`.* TO 'blog'@'localhost'" "write ${MANDAL_HOME_DIR}/blog/shared/.env (0600 blog:blog)" "write ${MANDAL_HOME_DIR}/blog/deploy.sh (0750 blog:blog)" "crontab for blog" "App blog created."; do
    if [[ "$create" == *"$marker"* ]]; then pass; else fail "app:create mentions: $marker"; fi
done
[[ "$create" == *"ALTER USER 'blog'@'localhost' IDENTIFIED BY '"*"'"* ]] && pass || fail "app:create sets a database password"
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

MANDAL_OS_RELEASE="${FIXTURES}/os-release-debian" bash -c 'source "'"${MANDAL_ROOT}"'/lib/core.sh"; mandal_load_commands; load_all_drivers; is_root(){ return 0; }; MANDAL_DRY_RUN=1 provision_apply' > /dev/null 2>&1
assert_eq "provisioning refuses an unsupported OS" "1" "$?"

# ---------------------------------------------------------------------------

printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
[[ "$FAILED" -eq 0 ]]
