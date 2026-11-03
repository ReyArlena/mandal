#!/usr/bin/env bash
#
# fail2ban:status / fail2ban:unban - the jails, what they have banned and
# a way to let an address back in. Jails are configured during provisioning.

MANDAL_COMMANDS+=("fail2ban:status|Report fail2ban's jails and banned addresses as JSON|read")
MANDAL_COMMANDS+=("fail2ban:unban|Unban an address (--ip=<address> [--jail=<name>])|write")

# One jail as {name, failed, banned, total_banned, ips: [...]}, read from
# `fail2ban-client status <jail>`. IPv6 addresses hold colons, so the lines
# are split on the label rather than on ":".
fail2ban_jail_json() {
    local status
    status="$(fail2ban-client status "$1" 2> /dev/null || true)"
    jq -n \
        --arg name "$1" \
        --arg failed "$(printf '%s\n' "$status" | sed -n 's/.*Currently failed:[[:space:]]*//p' | head -n 1)" \
        --arg banned "$(printf '%s\n' "$status" | sed -n 's/.*Currently banned:[[:space:]]*//p' | head -n 1)" \
        --arg total "$(printf '%s\n' "$status" | sed -n 's/.*Total banned:[[:space:]]*//p' | head -n 1)" \
        --arg ips "$(printf '%s\n' "$status" | sed -n 's/.*Banned IP list:[[:space:]]*//p' | head -n 1)" \
        '{
            name: $name,
            failed: (($failed | select(test("^[0-9]+$")) | tonumber) // 0),
            banned: (($banned | select(test("^[0-9]+$")) | tonumber) // 0),
            total_banned: (($total | select(test("^[0-9]+$")) | tonumber) // 0),
            ips: ($ips | split(" ") | map(select(length > 0)))
        }'
}

cmd_fail2ban_status() {
    require_root
    if ! has_cmd fail2ban-client; then
        jq -n --arg active "$(unit_state fail2ban)" '{installed: false, active: $active, jails: []}'
        return 0
    fi
    local list jail jails='[]'
    list="$(fail2ban-client status 2> /dev/null | awk -F':' '/Jail list:/ {print $2}' | tr ',' ' ' || true)"
    for jail in $list; do
        jails="$(jq -c --argjson jail "$(fail2ban_jail_json "$jail")" '. + [$jail]' <<< "$jails")"
    done
    jq -n --arg active "$(unit_state fail2ban)" --argjson jails "$jails" '{installed: true, active: $active, jails: $jails}'
}

cmd_fail2ban_unban() {
    local arg ip="" jail=""
    for arg in "$@"; do
        case "$arg" in
            --ip=*) ip="${arg#*=}" ;;
            --jail=*) jail="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$ip" =~ ^[0-9a-fA-F.:]+(/[0-9]{1,3})?$ ]] || die 2 "invalid address '$ip'"
    [[ -z "$jail" || "$jail" =~ ^[A-Za-z0-9_.-]{1,64}$ ]] || die 2 "invalid jail '$jail'"
    require_root
    has_cmd fail2ban-client || die "fail2ban is not installed; provision the server first"
    if ! dry_run; then
        with_lock
        audit "fail2ban:unban $ip${jail:+ ($jail)}"
    fi
    if [[ -n "$jail" ]]; then
        run_cmd fail2ban-client set "$jail" unbanip "$ip"
    else
        run_cmd fail2ban-client unban "$ip"
    fi
    printf 'Unbanned %s%s.\n' "$ip" "${jail:+ in $jail}"
}
