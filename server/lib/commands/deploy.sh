#!/usr/bin/env bash
#
# app:deploy / app:rollback / app:releases

MANDAL_COMMANDS+=("app:deploy|Deploy an app from its repository (zero downtime)|write")
MANDAL_COMMANDS+=("app:rollback|Switch an app back to an earlier release|write")
MANDAL_COMMANDS+=("app:releases|List an app's releases as JSON|read")
MANDAL_COMMANDS+=("app:configure|Change an app's repository, branch or releases to keep|write")

deploy_parse_name() {
    local arg
    DEPLOY_NAME=""
    for arg in "$@"; do
        case "$arg" in
            --name=*) DEPLOY_NAME="${arg#*=}" ;;
        esac
    done
    [[ -n "$DEPLOY_NAME" ]] || die 64 "--name is required"
    validate_name "$DEPLOY_NAME"
}

cmd_app_deploy() {
    local branch="" commit="" arg
    deploy_parse_name "$@"
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --branch=*) branch="${arg#*=}" ;;
            --commit=*) commit="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    load_all_drivers
    app_exists "$DEPLOY_NAME" || die "app '$DEPLOY_NAME' does not exist"
    if ! dry_run; then
        with_lock
        audit "app:deploy $DEPLOY_NAME ${branch:+branch=$branch} ${commit:+commit=$commit}"
    fi
    deploy_run "$DEPLOY_NAME" "$branch" "$commit"
}

cmd_app_rollback() {
    local release="" arg
    deploy_parse_name "$@"
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --release=*) release="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    require_root
    load_all_drivers
    app_exists "$DEPLOY_NAME" || die "app '$DEPLOY_NAME' does not exist"
    if ! dry_run; then
        with_lock
        audit "app:rollback $DEPLOY_NAME ${release:+release=$release}"
    fi
    deploy_rollback "$DEPLOY_NAME" "$release"
}

cmd_app_releases() {
    deploy_parse_name "$@"
    app_exists "$DEPLOY_NAME" || die "app '$DEPLOY_NAME' does not exist"
    deploy_releases_json "$DEPLOY_NAME"
}

cmd_app_configure() {
    local arg manifest
    deploy_parse_name "$@"
    require_root
    app_exists "$DEPLOY_NAME" || die "app '$DEPLOY_NAME' does not exist"
    manifest="$(app_conf_path "$DEPLOY_NAME")"
    with_lock
    audit "app:configure $DEPLOY_NAME"
    for arg in "$@"; do
        case "$arg" in
            --name=*) ;;
            --repo=*)
                validate_repo "${arg#*=}"
                conf_set "$manifest" REPO "${arg#*=}"
                ;;
            --branch=*)
                validate_branch "${arg#*=}"
                conf_set "$manifest" BRANCH "${arg#*=}"
                ;;
            --keep-releases=*)
                [[ "${arg#*=}" =~ ^[1-9][0-9]?$ ]] || die 2 "--keep-releases must be between 1 and 99"
                conf_set "$manifest" KEEP_RELEASES "${arg#*=}"
                ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    printf 'Updated %s.\n' "$manifest"
}
