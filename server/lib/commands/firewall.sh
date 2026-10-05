#!/usr/bin/env bash
#
# firewall:list / firewall:allow / firewall:delete - ufw rules, additive only.

MANDAL_COMMANDS+=("firewall:list|List ufw rules as JSON|read")
MANDAL_COMMANDS+=("firewall:allow|Allow a port (--port=443 [--proto=tcp|udp] [--from=<cidr>] [--comment=...])|write")
MANDAL_COMMANDS+=("firewall:delete|Delete a rule by its number from firewall:list (--number=N)|write")

firewall_rules_json() {
    local verbose numbered
    if ! has_cmd ufw; then
        echo '{"installed": false, "status": "unknown", "rules": []}'
        return 0
    fi
    verbose="$(ufw status verbose 2> /dev/null || true)"
    numbered="$({ ufw status numbered 2> /dev/null || true; } | awk '
        /^\[ *[0-9]+\]/ {
            line = $0
            match(line, /^\[ *[0-9]+\]/)
            number = substr(line, 2, RLENGTH - 2); gsub(/ /, "", number)
            rest = substr(line, RLENGTH + 1)
            sub(/^ +/, "", rest)
            comment = ""
            if (match(rest, /# .*$/)) { comment = substr(rest, RSTART + 2); rest = substr(rest, 1, RSTART - 1) }
            n = split(rest, parts, /  +/)
            to = parts[1]; action = parts[2]; from = parts[3]
            v6 = (to ~ /\(v6\)/ || from ~ /\(v6\)/) ? "true" : "false"
            gsub(/ \(v6\)/, "", to); gsub(/ \(v6\)/, "", from)
            printf "%s\t%s\t%s\t%s\t%s\t%s\n", number, to, action, from, v6, comment
        }
    ' | json_tsv number to action from ipv6 comment | jq 'map(.number |= tonumber | .ipv6 |= (. == "true"))')"
    jq -n \
        --arg status "$(printf '%s\n' "$verbose" | awk -F': ' '/^Status:/ {print $2}')" \
        --arg incoming "$(printf '%s\n' "$verbose" | grep -oE '[a-z]+ \(incoming\)' | awk '{print $1}' || true)" \
        --arg outgoing "$(printf '%s\n' "$verbose" | grep -oE '[a-z]+ \(outgoing\)' | awk '{print $1}' || true)" \
        --argjson rules "$numbered" \
        '{installed: true, status: ($status | if . == "" then "unknown" else . end), default_incoming: $incoming, default_outgoing: $outgoing, rules: $rules}'
}

cmd_firewall_list() {
    require_root
    firewall_rules_json
}

cmd_firewall_allow() {
    local arg port="" proto="tcp" from="" comment=""
    for arg in "$@"; do
        case "$arg" in
            --port=*) port="${arg#*=}" ;;
            --proto=*) proto="${arg#*=}" ;;
            --from=*) from="${arg#*=}" ;;
            --comment=*) comment="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$port" =~ ^[0-9]{1,5}(:[0-9]{1,5})?$ ]] || die 2 "invalid port '$port' (a number or a range such as 6000:6100)"
    [[ "$proto" =~ ^(tcp|udp)$ ]] || die 2 "invalid protocol '$proto'"
    [[ -z "$from" || "$from" =~ ^[0-9a-fA-F.:]+(/[0-9]{1,3})?$ ]] || die 2 "invalid source '$from' (an address or CIDR)"
    local comment_pattern='^[A-Za-z0-9 _.,:-]*$'
    [[ "${#comment}" -le 60 && "$comment" =~ $comment_pattern ]] || die 2 "invalid comment"
    require_root
    has_cmd ufw || die "ufw is not installed; provision the server first"
    if ! dry_run; then
        with_lock
        audit "firewall:allow $port/$proto ${from:+from $from}"
    fi
    local args=(allow)
    if [[ -n "$from" ]]; then
        args+=(from "$from" to any port "$port" proto "$proto")
    else
        args+=("$port/$proto")
    fi
    [[ -n "$comment" ]] && args+=(comment "$comment")
    run_cmd ufw "${args[@]}"
    printf 'Allowed %s/%s%s.\n' "$port" "$proto" "${from:+ from $from}"
}

cmd_firewall_delete() {
    local arg number=""
    for arg in "$@"; do
        case "$arg" in
            --number=*) number="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$number" =~ ^[1-9][0-9]{0,2}$ ]] || die 2 "--number must be a rule number from firewall:list"
    require_root
    has_cmd ufw || die "ufw is not installed"
    local rule
    rule="$(firewall_rules_json | jq -r --argjson n "$number" '.rules[] | select(.number == $n) | .to + " " + .action + " " + .from')"
    [[ -n "$rule" ]] || dry_run || die "no rule number $number"
    if [[ "$rule" == *"22/tcp"* || "$rule" == *"OpenSSH"* ]] || { [[ -n "$(ssh_setting port)" ]] && [[ "$rule" == *"$(ssh_setting port)/tcp"* ]]; }; then
        die 2 "refusing to delete the SSH rule ($rule); that would lock the panel out"
    fi
    if server_hosts_panel && [[ "$rule" == *"80/tcp"* || "$rule" == *"443/tcp"* ]]; then
        die 2 "refusing to delete the web rule ($rule); this server hosts the panel"
    fi
    if ! dry_run; then
        with_lock
        audit "firewall:delete $number ($rule)"
    fi
    run_cmd ufw --force delete "$number"
    printf 'Deleted rule %s (%s).\n' "$number" "$rule"
}
