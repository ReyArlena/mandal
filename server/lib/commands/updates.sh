#!/usr/bin/env bash
#
# updates:status / updates:apply / component:update / reboot

MANDAL_COMMANDS+=("updates:status|Pending updates, component versions and reboot state as JSON|read")
MANDAL_COMMANDS+=("updates:apply|Install updates (--security, --all or --package=<name>)|write")
MANDAL_COMMANDS+=("component:update|Update a standalone component (--component=cache/js/composer/docker)|write")
MANDAL_COMMANDS+=("reboot|Reboot the server after a short delay|write")

cmd_updates_status() {
    require_root
    load_all_drivers
    updates_status_json
}

cmd_updates_apply() {
    local arg mode="" package=""
    for arg in "$@"; do
        case "$arg" in
            --security) mode=security ;;
            --all) mode=all ;;
            --package=*) mode=package; package="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$mode" ]] || die 64 "choose --security, --all or --package=<name>"
    require_root
    load_all_drivers
    if ! dry_run; then
        with_lock
        audit "updates:apply $mode ${package}"
    fi
    case "$mode" in
        security) step "Installing security updates"; updates_apply_security ;;
        all) step "Installing all updates"; updates_apply_all ;;
        package) step "Updating $package"; updates_apply_package "$package" ;;
    esac
    updates_after_apt
    printf '\nUpdates installed.'
    if [[ -f "$MANDAL_REBOOT_REQUIRED" ]]; then
        printf ' A reboot is required.'
    fi
    printf '\n'
}

cmd_component_update() {
    local arg component=""
    for arg in "$@"; do
        case "$arg" in
            --component=*) component="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ "$component" =~ ^[a-z]+$ ]] || die 64 "--component is required"
    require_root
    load_all_drivers
    if ! dry_run; then
        with_lock
        audit "component:update $component"
    fi
    component_update "$component"
    printf '\nDone.\n'
}

cmd_reboot() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    dry_run || audit "reboot"
    printf 'Rebooting in 5 seconds.\n'
    # Detached so the SSH session that asked for it gets a clean reply first.
    run_cmd systemd-run --quiet --on-active=5 --timer-property=AccuracySec=1s /bin/systemctl reboot
}
