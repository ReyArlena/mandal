#!/usr/bin/env bash
#
# Zero-downtime deployments: clone into releases/<timestamp>, link shared
# files, run the app's deploy script, switch the `current` symlink, reload
# PHP-FPM, restart queue workers and prune old releases.

MANDAL_DEPLOY_LOG_DIR="${MANDAL_DEPLOY_LOG_DIR:-${MANDAL_LOG_DIR}/deploys}"

deploy_timestamp() {
    date -u +%Y%m%d%H%M%S
}

deploy_clone_url() {
    printf 'git@github.com:%s.git\n' "$1"
}

# Run a command as the app user with the deploy environment.
# deploy_as app home php [VAR=value ...] -- command...
deploy_as() {
    local name="$1" home="$2" php="$3" env=()
    shift 3
    while [[ $# -gt 0 && "$1" != "--" ]]; do
        env+=("$1")
        shift
    done
    shift || true
    run_cmd sudo -u "$name" -H env \
        "HOME=$home" \
        "PATH=$home/.local/bin:/usr/local/bin:/usr/bin:/bin" \
        "PHP=/usr/bin/php$php" \
        "GIT_SSH_COMMAND=ssh -i $home/.ssh/id_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new" \
        "${env[@]}" "$@"
}

# The releases directory listing, oldest first.
deploy_releases() {
    local home="$1"
    [[ -d "$home/releases" ]] || return 0
    find "$home/releases" -mindepth 1 -maxdepth 1 -type d -name '[0-9]*' -printf '%f\n' 2> /dev/null | sort
}

deploy_current_release() {
    local home="$1"
    [[ -L "$home/current" ]] || return 0
    basename "$(readlink -f "$home/current")"
}

deploy_link_shared() {
    local name="$1" home="$2" release="$3"
    run_cmd rm -rf "$release/storage"
    run_cmd ln -sfn "$home/shared/storage" "$release/storage"
    run_cmd ln -sfn "$home/shared/.env" "$release/.env"
    run_cmd chown -h "$name:$name" "$release/storage" "$release/.env"
}

deploy_switch() {
    local name="$1" home="$2" release="$3"
    run_cmd ln -sfn "$release" "$home/current.new"
    run_cmd chown -h "$name:$name" "$home/current.new"
    run_cmd mv -T "$home/current.new" "$home/current"
}

deploy_reload_php() {
    service_reload_or_restart "php$1-fpm"
}

# Restart queue workers (and Horizon/Reverb when present) so they pick up the new code.
deploy_restart_workers() {
    local name="$1" home="$2" php="$3"
    local release="$home/current"
    deploy_as "$name" "$home" "$php" -- "/usr/bin/php$php" "$release/artisan" queue:restart || warn "queue:restart failed"
    if [[ -d "$release/vendor/laravel/horizon" ]] || dry_run; then
        deploy_as "$name" "$home" "$php" -- "/usr/bin/php$php" "$release/artisan" horizon:terminate || true
    fi
    if [[ -d "$release/vendor/laravel/reverb" ]]; then
        deploy_as "$name" "$home" "$php" -- "/usr/bin/php$php" "$release/artisan" reverb:restart || true
    fi
}

deploy_prune() {
    local home="$1" keep="$2" releases count
    mapfile -t releases < <(deploy_releases "$home")
    count=${#releases[@]}
    while [[ $count -gt $keep ]]; do
        run_cmd rm -rf "$home/releases/${releases[0]}"
        releases=("${releases[@]:1}")
        count=$((count - 1))
    done
}

deploy_record() {
    local release="$1" branch="$2" commit="$3"
    write_file "$release/.mandal-release" 0644 <<EOF
BRANCH=${branch}
COMMIT=${commit}
DEPLOYED_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF
}

# deploy_run name branch [commit]
deploy_run() {
    local name="$1" branch="$2" commit="${3:-}" manifest home php repo keep timestamp release log
    manifest="$(app_conf_path "$name")"
    home="$(app_home "$name")"
    php="$(conf_get "$manifest" PHP)" || die "app '$name' has no PHP version in its manifest"
    repo="$(conf_get "$manifest" REPO)" || die "app '$name' has no repository; set one with app:configure"
    keep="$(conf_get "$manifest" KEEP_RELEASES 2> /dev/null || echo 5)"
    [[ -n "$branch" ]] || branch="$(conf_get "$manifest" BRANCH 2> /dev/null || echo main)"
    validate_branch "$branch"
    [[ -z "$commit" || "$commit" =~ ^[0-9a-f]{7,40}$ ]] || die 2 "invalid commit '$commit'"

    timestamp="$(deploy_timestamp)"
    release="$home/releases/$timestamp"
    log="$MANDAL_DEPLOY_LOG_DIR/$name/$timestamp.log"
    dry_run || mkdir -p "$(dirname "$log")"
    dry_run && log=/dev/null

    {
        step "Deploying $name from $repo@$branch"
        printf 'release: %s\n' "$release"
        deploy_as "$name" "$home" "$php" -- git clone --quiet --depth 1 --branch "$branch" "$(deploy_clone_url "$repo")" "$release"
        if [[ -n "$commit" ]]; then
            deploy_as "$name" "$home" "$php" -- git -C "$release" fetch --quiet --depth 1 origin "$commit"
            deploy_as "$name" "$home" "$php" -- git -C "$release" checkout --quiet "$commit"
        fi
        if ! dry_run; then
            commit="$(sudo -u "$name" git -C "$release" rev-parse HEAD)"
        fi
        printf 'commit: %s\n' "${commit:-unknown}"

        step "Linking shared files"
        deploy_link_shared "$name" "$home" "$release"

        step "Running deploy.sh"
        deploy_as "$name" "$home" "$php" "APP_NAME=$name" "RELEASE_DIR=$release" "SHARED_DIR=$home/shared" "BRANCH=$branch" "COMMIT=${commit:-}" \
            -- bash -c "cd '$release' && exec bash '$home/deploy.sh'"

        step "Activating the release"
        deploy_record "$release" "$branch" "${commit:-}"
        deploy_switch "$name" "$home" "$release"
        deploy_reload_php "$php"
        deploy_restart_workers "$name" "$home" "$php"

        step "Pruning old releases (keeping $keep)"
        deploy_prune "$home" "$keep"

        printf '\nDeployed %s (%s).\n' "$name" "${commit:-$branch}"
    } 2>&1 | tee -a "$log"

    local status="${PIPESTATUS[0]}"
    if [[ "$status" -ne 0 ]] && ! dry_run && [[ -d "$release" ]] && [[ "$(deploy_current_release "$home")" != "$timestamp" ]]; then
        printf 'Deployment failed; removing the unfinished release %s\n' "$timestamp" | tee -a "$log"
        rm -rf "$release"
    fi
    return "$status"
}

# deploy_rollback name [release]
deploy_rollback() {
    local name="$1" target="${2:-}" manifest home php current releases
    manifest="$(app_conf_path "$name")"
    home="$(app_home "$name")"
    php="$(conf_get "$manifest" PHP)" || die "app '$name' has no PHP version in its manifest"
    current="$(deploy_current_release "$home")"
    mapfile -t releases < <(deploy_releases "$home")

    if [[ -z "$target" ]]; then
        local previous="" release
        for release in "${releases[@]}"; do
            [[ "$release" == "$current" ]] && break
            previous="$release"
        done
        target="$previous"
    fi
    [[ -n "$target" ]] || die "no earlier release to roll back to"
    [[ "$target" =~ ^[0-9]{14}$ ]] || die 2 "invalid release '$target'"
    [[ -d "$home/releases/$target" ]] || dry_run || die "release '$target' does not exist"

    step "Rolling $name back to release $target"
    deploy_switch "$name" "$home" "$home/releases/$target"
    deploy_reload_php "$php"
    deploy_restart_workers "$name" "$home" "$php"
    printf '\nRolled back %s to %s (migrations are not reversed).\n' "$name" "$target"
}

# JSON list of releases with the current one marked.
deploy_releases_json() {
    local name="$1" home current release file
    home="$(app_home "$name")"
    current="$(deploy_current_release "$home")"
    for release in $(deploy_releases "$home"); do
        file="$home/releases/$release/.mandal-release"
        printf '%s\t%s\t%s\t%s\t%s\n' "$release" \
            "$(conf_get "$file" COMMIT 2> /dev/null || true)" \
            "$(conf_get "$file" BRANCH 2> /dev/null || true)" \
            "$(conf_get "$file" DEPLOYED_AT 2> /dev/null || true)" \
            "$([[ "$release" == "$current" ]] && echo true || echo false)"
    done | json_tsv release commit branch deployed_at current | jq 'map(.current |= (. == "true"))'
}
