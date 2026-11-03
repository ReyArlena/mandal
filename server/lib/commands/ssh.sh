#!/usr/bin/env bash
#
# ssh:status - what sshd allows and the keys that can run mandal, for the
# SSH page of the panel. Read-only; keys change through key:add/key:remove.

MANDAL_COMMANDS+=("ssh:status|Report sshd settings and the panels' SSH keys as JSON|read")

# {sshd: {installed, active, hardened, port, password_authentication,
#  permit_root_login, pubkey_authentication, max_auth_tries}, keys: [...]}
cmd_ssh_status() {
    require_root
    local sshd hardened=false tries=""
    sshd="$(provision_ssh)"
    [[ -f "$MANDAL_SSHD_DROPIN_DIR/10-mandal.conf" ]] && hardened=true
    has_cmd sshd && tries="$(ssh_setting maxauthtries)"
    jq -n \
        --argjson sshd "$sshd" \
        --argjson hardened "$hardened" \
        --arg active "$(unit_state ssh)" \
        --arg tries "$tries" \
        --argjson keys "$(cmd_key_list)" \
        '{
            sshd: ($sshd + {
                active: $active,
                hardened: $hardened,
                max_auth_tries: (if $tries == "" then null else ($tries | tonumber) end)
            }),
            keys: $keys
        }'
}
