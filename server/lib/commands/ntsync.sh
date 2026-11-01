#!/usr/bin/env bash
#
# ntsync:enable - load the ntsync driver now and at every boot.

MANDAL_COMMANDS+=("ntsync:enable|Load the ntsync driver for Windows game servers under Proton or Wine, now and at boot|write")

cmd_ntsync_enable() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    ntsync_supported || die 2 "kernel $(uname -r) has no ntsync driver; it needs Linux 6.14 or newer with the ntsync module"
    if ntsync_active && ntsync_persistent; then
        printf 'ntsync is already active and loads at boot.\n'
        return 0
    fi
    if ! dry_run; then
        with_lock
        audit "ntsync:enable"
    fi

    step "Loading the ntsync driver"
    if ntsync_active; then
        printf '+ %s already exists\n' "$MANDAL_NTSYNC_DEVICE"
    else
        run_cmd modprobe ntsync
        dry_run || ntsync_active || die "modprobe ntsync succeeded but $MANDAL_NTSYNC_DEVICE did not appear"
    fi

    step "Loading it at boot"
    if ntsync_persistent; then
        printf '+ already listed in %s\n' "$MANDAL_MODULES_LOAD_DIR"
    else
        write_file "$MANDAL_MODULES_LOAD_DIR/ntsync.conf" 0644 <<< "ntsync"
    fi

    printf '\nntsync is active. Containers use it once their compose file passes the device:\n    devices:\n      - /dev/ntsync:/dev/ntsync\n'
}
