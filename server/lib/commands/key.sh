#!/usr/bin/env bash
#
# key:list / key:add / key:remove - the SSH keys allowed to run mandal,
# one per panel, kept in the mandal user's authorized_keys with a label.

MANDAL_COMMANDS+=("key:list|List the panels' SSH keys as JSON|read")
MANDAL_COMMANDS+=("key:add|Allow another panel's public key (--key='ssh-ed25519 ...' --name=<label>)|write")
MANDAL_COMMANDS+=("key:remove|Remove a panel's key by its fingerprint (--fingerprint=SHA256:...)|write")

MANDAL_SSH_USER="${MANDAL_SSH_USER:-mandal}"
MANDAL_SUDOERS_FILE="${MANDAL_SUDOERS_FILE:-/etc/sudoers.d/mandal}"

key_authorized_file() {
    printf '%s/%s/.ssh/authorized_keys\n' "$MANDAL_HOME_DIR" "$MANDAL_SSH_USER"
}

# setup.sh normally creates the SSH user and its sudo rule. A hosted panel
# never runs setup.sh, so key:add makes sure both exist before adding a key.
key_ensure_user() {
    local user="$MANDAL_SSH_USER" rule tmp
    if id "$user" > /dev/null 2>&1; then
        :
    else
        run_cmd useradd --create-home --home-dir "$MANDAL_HOME_DIR/$user" --shell /bin/bash --user-group "$user"
    fi
    rule="$user ALL=(root) NOPASSWD: $MANDAL_ROOT/bin/mandal"
    if [[ -f "$MANDAL_SUDOERS_FILE" ]] && [[ "$(cat "$MANDAL_SUDOERS_FILE")" == "$rule" ]]; then
        return 0
    fi
    printf '+ write %s: %s\n' "$MANDAL_SUDOERS_FILE" "$rule"
    dry_run && return 0
    mkdir -p "$(dirname "$MANDAL_SUDOERS_FILE")"
    tmp="$(mktemp "$MANDAL_SUDOERS_FILE.XXXXXX")"
    printf '%s\n' "$rule" > "$tmp"
    chmod 0440 "$tmp"
    if ! visudo -cf "$tmp" > /dev/null; then
        rm -f "$tmp"
        die "the sudo rule for $user failed validation"
    fi
    mv -f "$tmp" "$MANDAL_SUDOERS_FILE"
}

# SHA256 fingerprint of an OpenSSH public key line, like ssh-keygen -lf.
key_fingerprint() {
    local blob
    blob="$(awk '{print $2}' <<< "$1")"
    printf 'SHA256:%s\n' "$(printf '%s' "$blob" | base64 -d 2> /dev/null | sha256sum | awk '{print $1}' | xxd -r -p | base64 | tr -d '=')"
}

validate_public_key() {
    local type blob
    type="$(awk '{print $1}' <<< "$1")"
    blob="$(awk '{print $2}' <<< "$1")"
    [[ "$type" =~ ^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com)$ ]] || die 2 "unsupported key type '$type'"
    [[ -n "$blob" && "$blob" =~ ^[A-Za-z0-9+/=]+$ ]] || die 2 "invalid public key"
    printf '%s' "$blob" | base64 -d > /dev/null 2>&1 || die 2 "invalid public key"
    [[ "$1" != *$'\n'* ]] || die 2 "invalid public key"
}

cmd_key_list() {
    local file
    require_root
    file="$(key_authorized_file)"
    if [[ ! -f "$file" ]]; then
        echo '[]'
        return 0
    fi
    while IFS= read -r line; do
        [[ "$line" =~ ^(ssh-|ecdsa-|sk-) ]] || continue
        printf '%s\t%s\t%s\n' "$(key_fingerprint "$line")" "$(awk '{print $1}' <<< "$line")" "$(awk '{$1 = ""; $2 = ""; sub(/^  /, ""); print}' <<< "$line")"
    done < "$file" | json_tsv fingerprint type comment
}

cmd_key_add() {
    local arg key="" name="" file
    for arg in "$@"; do
        case "$arg" in
            --key=*) key="${arg#*=}" ;;
            --name=*) name="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$key" ]] || die 64 "--key is required"
    [[ -n "$name" ]] || die 64 "--name is required"
    local name_pattern='^[A-Za-z0-9 _.()-]{1,64}$'
    [[ "$name" =~ $name_pattern ]] || die 2 "invalid name"
    validate_public_key "$key"
    require_root
    file="$(key_authorized_file)"
    local line
    line="$(awk '{print $1, $2}' <<< "$key") mandal-panel: $name"
    if [[ -f "$file" ]] && grep -qF "$(awk '{print $2}' <<< "$key")" "$file"; then
        printf 'This key is already allowed.\n'
        return 0
    fi
    if ! dry_run; then
        with_lock
        audit "key:add $name $(key_fingerprint "$key")"
    fi
    key_ensure_user
    run_cmd install -d -m 0700 -o "$MANDAL_SSH_USER" -g "$MANDAL_SSH_USER" "$(dirname "$file")"
    ensure_line "$file" "$line"
    if ! dry_run; then
        chmod 0600 "$file"
        chown "$MANDAL_SSH_USER:$MANDAL_SSH_USER" "$file"
    fi
    printf 'Added key %s for "%s".\n' "$(key_fingerprint "$key")" "$name"
}

cmd_key_remove() {
    local arg fingerprint="" file tmp
    for arg in "$@"; do
        case "$arg" in
            --fingerprint=*) fingerprint="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] || die 2 "--fingerprint must look like SHA256:..."
    require_root
    file="$(key_authorized_file)"
    [[ -f "$file" ]] || die "no keys are configured"
    local total
    total="$(grep -cE '^(ssh-|ecdsa-|sk-)' "$file" || true)"
    [[ "$total" -gt 1 ]] || die 2 "refusing to remove the last key; that would lock every panel out"
    if ! dry_run; then
        with_lock
        audit "key:remove $fingerprint"
    fi
    printf '+ remove %s from %s\n' "$fingerprint" "$file"
    dry_run && return 0
    backup_file "$file"
    tmp="$(mktemp)"
    while IFS= read -r line; do
        if [[ "$line" =~ ^(ssh-|ecdsa-|sk-) ]] && [[ "$(key_fingerprint "$line")" == "$fingerprint" ]]; then
            continue
        fi
        printf '%s\n' "$line"
    done < "$file" > "$tmp"
    chmod 0600 "$tmp"
    chown "$MANDAL_SSH_USER:$MANDAL_SSH_USER" "$tmp"
    mv -f "$tmp" "$file"
    printf 'Removed %s.\n' "$fingerprint"
}
