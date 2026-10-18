<?php

namespace App\Mandal;

use App\Models\Server;
use App\Ssh\KeyPair;

/**
 * Renders the Bash script a fresh server runs as root to let the panel in:
 * it creates the SSH user, installs the panel's public key and the mandal
 * CLI, and limits sudo to that CLI. It changes nothing else.
 */
class SetupScript
{
    public function render(Server $server, string $cliUrl, string $version): string
    {
        $user = escapeshellarg($server->ssh_user);
        $publicKey = escapeshellarg(KeyPair::normalize($server->public_key).' mandal-panel');
        $cliUrl = escapeshellarg($cliUrl);
        $version = escapeshellarg($version);

        return <<<BASH
        #!/usr/bin/env bash
        #
        # Mandal server setup. Run as root on a fresh Ubuntu 24.04 or 26.04 server.
        #
        # Creates the SSH user Mandal connects as, installs the panel's public key
        # and the mandal CLI, and lets that user run only the CLI through sudo.
        # Nothing else on the server is changed; provisioning happens from the panel.

        set -euo pipefail

        user={$user}
        public_key={$publicKey}
        cli_url={$cliUrl}
        version={$version}
        home="/home/\$user"
        install_dir="/opt/mandal"

        if [[ "\$(id -u)" -ne 0 ]]; then
            echo "error: run this script as root (sudo -i, then run it again)" >&2
            exit 1
        fi

        if [[ ! "\$user" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
            echo "error: invalid user name '\$user'" >&2
            exit 1
        fi

        if ! command -v jq > /dev/null 2>&1 || ! command -v curl > /dev/null 2>&1; then
            echo "Installing jq and curl"
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -qq
            apt-get install -y -qq jq curl tar > /dev/null
        fi

        if ! id "\$user" > /dev/null 2>&1; then
            echo "Creating user \$user"
            useradd --create-home --shell /bin/bash --user-group "\$user"
        fi

        install -d -m 0700 -o "\$user" -g "\$user" "\$home/.ssh"
        touch "\$home/.ssh/authorized_keys"
        if ! grep -qF "\$(printf '%s' "\$public_key" | awk '{print \$2}')" "\$home/.ssh/authorized_keys"; then
            echo "Adding the panel's public key"
            printf '%s\\n' "\$public_key" >> "\$home/.ssh/authorized_keys"
        fi
        chmod 0600 "\$home/.ssh/authorized_keys"
        chown "\$user:\$user" "\$home/.ssh/authorized_keys"

        echo "Installing the mandal CLI \$version to \$install_dir"
        tmp="\$(mktemp -d)"
        trap 'rm -rf "\$tmp"' EXIT
        curl -fsSL "\$cli_url" | tar -xzf - -C "\$tmp"
        if [[ ! -x "\$tmp/bin/mandal" ]]; then
            echo "error: the downloaded archive does not contain bin/mandal" >&2
            exit 1
        fi
        rm -rf "\$install_dir"
        mkdir -p "\$install_dir"
        cp -R "\$tmp/." "\$install_dir/"
        chown -R root:root "\$install_dir"
        chmod -R go-w "\$install_dir"
        chmod 0755 "\$install_dir/bin/mandal"
        ln -sf "\$install_dir/bin/mandal" /usr/local/bin/mandal

        echo "Allowing \$user to run the CLI as root"
        sudoers="/etc/sudoers.d/mandal"
        printf '%s ALL=(root) NOPASSWD: %s/bin/mandal\\n' "\$user" "\$install_dir" > "\$sudoers.tmp"
        chmod 0440 "\$sudoers.tmp"
        visudo -cf "\$sudoers.tmp" > /dev/null
        mv -f "\$sudoers.tmp" "\$sudoers"

        echo
        echo "Done. mandal \$(mandal version) is installed and the panel can now connect as \$user."
        echo "Return to the panel and use 'Test connection'."

        BASH;
    }
}
