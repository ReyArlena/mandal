#!/usr/bin/env bash
#
# cli:update - replace the installed CLI with an archive the panel uploaded.

MANDAL_COMMANDS+=("cli:update|Install a new version of the CLI from a tar.gz (--from=<file>)|write")

cmd_cli_update() {
    local from="" arg tmp install_dir
    for arg in "$@"; do
        case "$arg" in
            --from=*) from="${arg#*=}" ;;
            --dry-run) export MANDAL_DRY_RUN=1 ;;
            *) die 64 "unknown option '$arg'" ;;
        esac
    done
    [[ -n "$from" ]] || die 64 "--from=<archive> is required"
    [[ -f "$from" && ! -L "$from" ]] || die 2 "archive not found: $from"
    require_root
    install_dir="$(readlink -f "$MANDAL_ROOT")"
    [[ "$install_dir" == /opt/* ]] || dry_run || die "refusing to replace a CLI outside /opt ($install_dir)"

    tmp="$(mktemp -d)"
    step "Installing the CLI from $from"
    run_cmd tar -xzf "$from" -C "$tmp"
    if ! dry_run; then
        [[ -x "$tmp/bin/mandal" && -f "$tmp/lib/core.sh" ]] || die "the archive does not contain a mandal CLI"
        bash -n "$tmp/bin/mandal" || die "the new CLI does not parse"
    fi
    with_lock
    audit "cli:update $(tr -d '[:space:]' < "$tmp/VERSION" 2> /dev/null || echo unknown)"
    run_cmd chown -R root:root "$tmp"
    run_cmd chmod -R go-w "$tmp"
    run_cmd chmod 0755 "$tmp/bin/mandal" "$tmp"
    run_cmd rm -rf "${install_dir}.old"
    run_cmd mv "$install_dir" "${install_dir}.old"
    run_cmd mv "$tmp" "$install_dir"
    run_cmd rm -rf "${install_dir}.old" "$from"
    printf 'Installed mandal %s (build %s).\n' "$(tr -d '[:space:]' < "$install_dir/VERSION" 2> /dev/null || echo unknown)" "$(MANDAL_ROOT="$install_dir" mandal_build_hash)"
}
