#!/usr/bin/env bash

MANDAL_COMMANDS+=("version|Print the installed mandal version|read")

cmd_version() {
    local json=false
    [[ "${1:-}" == "--json" ]] && json=true
    if [[ "$json" == true ]]; then
        jq -n --arg version "$(mandal_version)" --arg build "$(mandal_build_hash)" '{version: $version, build: $build}'
    else
        mandal_version
    fi
}
