#!/usr/bin/env bash

MANDAL_COMMANDS+=("version|Print the installed mandal version|read")

cmd_version() {
    local json=false
    [[ "${1:-}" == "--json" ]] && json=true
    if [[ "$json" == true ]]; then
        jq -n --arg version "$(mandal_version)" '{version: $version}'
    else
        mandal_version
    fi
}
