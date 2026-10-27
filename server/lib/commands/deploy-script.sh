#!/usr/bin/env bash
#
# app:deploy-script - read, replace or reset an app's deploy.sh.

MANDAL_COMMANDS+=("app:deploy-script|Print an app's deploy.sh, replace it with --from=<file>, or --reset it to the default|write")

cmd_app_deploy_script() {
    local name="" from="" reset=false arg home target
    for arg in "$@"; do
        case "$arg" in
            --name=*) name="${arg#*=}" ;;
            --from=*) from="${arg#*=}" ;;
            --reset) reset=true ;;
            --default) app_render_deploy_script; return 0 ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$name" ]] || die 64 "--name is required"
    validate_name "$name"
    require_root
    app_exists "$name" || die "app '$name' does not exist"
    [[ "$(conf_get "$(app_conf_path "$name")" TYPE 2> /dev/null || echo laravel)" != "docker" ]] || die "Docker apps have no deploy script; edit the compose file instead"
    home="$(app_home "$name")"
    target="$home/deploy.sh"

    if [[ -z "$from" && "$reset" == false ]]; then
        [[ -f "$target" ]] || die "no deploy.sh for app '$name'"
        cat "$target"
        return 0
    fi

    [[ -z "$from" || "$reset" == false ]] || die 64 "--from and --reset do not go together"
    if ! dry_run; then
        with_lock
        audit "app:deploy-script $name $([[ "$reset" == true ]] && echo reset || echo write)"
    fi
    if [[ "$reset" == true ]]; then
        write_file "$target" 0750 "$name:$name" < <(app_render_deploy_script)
        printf 'Restored the default deploy.sh for %s.\n' "$name"
        return 0
    fi
    app_write_deploy_script "$name" "$from"
    printf 'Updated %s. It runs on the next deploy.\n' "$target"
}
