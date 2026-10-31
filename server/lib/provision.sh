#!/usr/bin/env bash
#
# Provisioning steps. Each is idempotent: it looks at what exists, adds
# what is missing through drop-in files, and never replaces configuration
# it did not write. See `mandal provision --check` for the preflight.

MANDAL_COMPOSER_INSTALLER="https://getcomposer.org/installer"
MANDAL_COMPOSER_SIGNATURE="https://composer.github.io/installer.sig"
MANDAL_DOCKER_KEY="https://download.docker.com/linux/ubuntu/gpg"
MANDAL_DOCKER_REPO="https://download.docker.com/linux/ubuntu"

# shellcheck disable=SC2034  # consumed by commands/provision.sh
MANDAL_PROVISION_STEPS=(
    base_packages
    time
    swap
    sysctl
    nginx
    php
    composer
    database
    backups
    cache
    js
    supervisor
    certbot
    docker
    logrotate
    fail2ban
    unattended_upgrades
    firewall
    ssh_hardening
    record
)

prov_base_packages() {
    step "Base packages"
    apt_update
    apt_install ca-certificates curl gnupg git unzip tar jq acl zstd rclone cron openssh-server
}

prov_time() {
    step "Time zone and clock"
    if has_cmd timedatectl; then
        if [[ "$(timedatectl show -p Timezone --value 2> /dev/null || true)" != "UTC" ]]; then
            run_cmd timedatectl set-timezone UTC
        else
            printf '+ time zone already UTC\n'
        fi
        run_cmd timedatectl set-ntp true
    fi
}

prov_swap() {
    step "Swap"
    if swap_present; then
        printf '+ swap already configured, leaving it alone\n'
        return 0
    fi
    local size_mb="${MANDAL_SWAP_MB:-2048}"
    run_cmd fallocate -l "${size_mb}M" /swapfile
    run_cmd chmod 0600 /swapfile
    run_cmd mkswap /swapfile
    run_cmd swapon /swapfile
    ensure_line /etc/fstab "/swapfile none swap sw 0 0"
}

prov_sysctl() {
    step "Kernel parameters"
    write_file /etc/sysctl.d/60-mandal.conf 0644 <<'EOF'
# managed-by: mandal
vm.swappiness = 10
net.core.somaxconn = 1024
EOF
    # Only this file is applied: reloading every sysctl.d file also re-applies
    # distribution defaults, and a key the kernel refuses (common in
    # containers) would fail the whole step. Such keys apply at the next boot.
    run_cmd sysctl -q -p /etc/sysctl.d/60-mandal.conf || warn "some kernel parameters could not be applied now; they apply at the next boot"
}

prov_nginx() {
    step "nginx"
    apt_install nginx
    local settings="" directive
    for directive in "server_tokens off" "client_max_body_size 64m"; do
        if nginx_conf_sets "${directive%% *}"; then
            printf '+ nginx.conf already sets %s; leaving it there\n' "${directive%% *}"
        else
            settings+="${directive};"$'\n'
        fi
    done
    settings="${settings%$'\n'}"
    write_file "$MANDAL_NGINX_DIR/conf.d/mandal.conf" 0644 <<EOF
# managed-by: mandal
${settings}
EOF
    nginx_default_server
    nginx_check_and_reload
}

# Whether nginx.conf itself sets a directive. nginx refuses a directive set
# twice in the same block, so conf.d must not repeat it. Ubuntu 26.04 ships
# "server_tokens build;", which is why every site mandal writes also sets
# server_tokens itself.
nginx_conf_sets() {
    grep -Eqs "^[[:space:]]*$1[[:space:]]" "$MANDAL_NGINX_DIR/nginx.conf"
}

# A catch-all that drops requests for unknown host names. Ubuntu ships a
# default site that serves a welcome page; it is disabled only when it is
# still the unmodified package file, otherwise it is left as it is.
nginx_default_server() {
    local stock="$MANDAL_NGINX_DIR/sites-available/default" enabled="$MANDAL_NGINX_DIR/sites-enabled/default"
    if [[ -L "$enabled" ]] && nginx_stock_default_unmodified; then
        printf '+ disable the stock default site (unmodified package file)\n'
        run_cmd rm -f "$enabled"
    elif [[ -L "$enabled" ]]; then
        printf '+ keeping the existing default site at %s (it has been customised)\n' "$stock"
        return 0
    fi
    write_file "$MANDAL_NGINX_DIR/sites-available/mandal-default" 0644 <<'EOF'
# managed-by: mandal
# Drop requests that do not match a configured site.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444;
}
EOF
    run_cmd ln -sf "$MANDAL_NGINX_DIR/sites-available/mandal-default" "$MANDAL_NGINX_DIR/sites-enabled/mandal-default"
}

nginx_stock_default_unmodified() {
    has_cmd dpkg || return 1
    ! dpkg -V nginx-common 2> /dev/null | grep -q 'sites-available/default'
}

nginx_check_and_reload() {
    if dry_run; then
        printf '+ nginx -t && systemctl reload nginx\n'
        return 0
    fi
    nginx -t 2>&1 | sed 's/^/  /' || die "nginx configuration test failed; nothing was reloaded"
    service_enable_now nginx
    service_reload_or_restart nginx
}

prov_php() {
    step "PHP"
    local version
    php_add_ppa
    if [[ -n "${MANDAL_PHP_VERSION:-}" ]]; then
        version="$MANDAL_PHP_VERSION"
    else
        version="$(php_newest_supported_version)" || die "no PHP version with all required extensions is available"
    fi
    printf '+ PHP version: %s\n' "$version"
    php_install_version "$version"
    php_set_default "$version"
}

prov_composer() {
    step "Composer"
    if has_cmd composer; then
        printf '+ composer already installed (%s)\n' "$(composer --version 2> /dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | awk 'NR == 1')"
        return 0
    fi
    local installer expected actual
    download "$MANDAL_COMPOSER_INSTALLER"
    installer="$DOWNLOADED_FILE"
    if ! dry_run; then
        expected="$(curl -fsSL "$MANDAL_COMPOSER_SIGNATURE")"
        actual="$(sha384sum "$installer" | awk '{print $1}')"
        [[ "$expected" == "$actual" ]] || die "composer installer signature mismatch"
    fi
    run_cmd php "$installer" --quiet --install-dir=/usr/local/bin --filename=composer
    dry_run || rm -f "$installer"
}

prov_database() {
    step "Database ($(db_driver))"
    db_install
}

# Database backups are on by default: a local nightly dump, written once
# and never over a user's own settings.
prov_backups() {
    step "Database backups"
    backup_configure_defaults
}

prov_cache() {
    step "Cache ($(cache_driver))"
    cache_install
}

prov_js() {
    step "JavaScript tooling ($(js_driver))"
    js_install
}

prov_supervisor() {
    step "Supervisor"
    apt_install supervisor
    service_enable_now supervisor
}

prov_certbot() {
    step "Certbot"
    apt_install certbot python3-certbot-nginx
    run_cmd systemctl enable --now --quiet certbot.timer
}

prov_docker() {
    step "Docker"
    if has_cmd docker; then
        printf '+ docker already installed\n'
    else
        apt_add_source docker "$MANDAL_DOCKER_KEY" "$MANDAL_DOCKER_REPO" "$(os_codename)" stable
        apt_install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    fi
    service_enable_now docker
    if id mandal > /dev/null 2>&1 && ! id -nG mandal | grep -qw docker; then
        run_cmd usermod -aG docker mandal
    fi
}

prov_logrotate() {
    step "Log rotation"
    write_file /etc/logrotate.d/mandal 0644 <<'EOF'
# managed-by: mandal
/var/log/mandal/*.log /var/log/mandal/deploys/*/*.log {
    weekly
    rotate 8
    missingok
    notifempty
    compress
    delaycompress
}

/home/*/shared/storage/logs/*.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    su root adm
}
EOF
}

prov_fail2ban() {
    step "fail2ban"
    apt_install fail2ban
    write_file /etc/fail2ban/jail.d/mandal.local 0644 <<'EOF'
# managed-by: mandal
[DEFAULT]
backend = systemd

[sshd]
enabled = true
EOF
    service_enable_now fail2ban
    service_restart fail2ban
}

# Ubuntu security updates, PHP from the PPA and the Docker engine (its
# repository's Release file carries Origin: Docker) install themselves.
unattended_render_config() {
    cat <<'EOF'
// managed-by: mandal
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
Unattended-Upgrade::Origins-Pattern {
    "origin=Ubuntu,archive=${distro_codename}-security";
    "origin=UbuntuESMApps,archive=${distro_codename}-apps-security";
    "origin=UbuntuESM,archive=${distro_codename}-infra-security";
    "origin=LP-PPA-ondrej-php";
    "origin=Docker";
};
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
EOF
}

prov_unattended_upgrades() {
    step "Automatic security updates"
    apt_install unattended-upgrades
    write_file /etc/apt/apt.conf.d/52-mandal 0644 < <(unattended_render_config)
    service_enable_now unattended-upgrades
}

prov_firewall() {
    step "Firewall (ufw)"
    apt_install ufw
    local port
    port="$(ssh_setting port)"
    port="${port:-22}"
    run_cmd ufw --force default deny incoming
    run_cmd ufw --force default allow outgoing
    run_cmd ufw allow "${port}/tcp" comment "SSH"
    run_cmd ufw allow 80/tcp comment "HTTP"
    run_cmd ufw allow 443/tcp comment "HTTPS"
    if [[ "$(ufw status 2> /dev/null | awk '/^Status:/ {print $2}')" == "active" ]]; then
        printf '+ ufw already active\n'
    else
        run_cmd ufw --force enable
    fi
    # A freshly installed ufw has its boot unit enabled but not started, so
    # systemd (and the inventory) would report the firewall as inactive.
    service_enable_now ufw
}

# Applied last: by the time this runs the panel has already signed in with
# its key, so disabling password and root logins cannot lock it out.
prov_ssh_hardening() {
    step "SSH hardening"
    if [[ "${MANDAL_SKIP_SSH_HARDENING:-0}" == "1" ]]; then
        printf '+ skipped (--skip-ssh-hardening)\n'
        return 0
    fi
    write_file "$MANDAL_SSHD_DROPIN_DIR/10-mandal.conf" 0644 <<'EOF'
# managed-by: mandal
# Read before the other drop-ins (sshd keeps the first value it sees).
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
PubkeyAuthentication yes
MaxAuthTries 3
EOF
    if dry_run; then
        printf '+ sshd -t && systemctl reload ssh\n'
        return 0
    fi
    sshd_run_dir
    sshd -t || die "sshd configuration test failed; the SSH service was not reloaded"
    service_reload_or_restart ssh
}

prov_record() {
    step "Recording the result"
    local file="$MANDAL_SERVER_CONF"
    dry_run && {
        printf '+ write %s\n' "$file"
        return 0
    }
    mkdir -p "$MANDAL_ETC" "$MANDAL_APPS_DIR" "$MANDAL_LOG_DIR"
    chmod 0755 "$MANDAL_ETC"
    conf_set "$file" DATABASE "$(db_driver)"
    conf_set "$file" CACHE "$(cache_driver)"
    conf_set "$file" JS "$(js_driver)"
    conf_set "$file" SSH_PORT "$(ssh_setting port || echo 22)"
    conf_set "$file" PROVISIONED_AT "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    conf_set "$file" PROVISIONED_BY "mandal $(mandal_version)"
    chmod 0644 "$file"
    printf '+ wrote %s\n' "$file"
}
