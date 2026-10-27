# Server CLI

Every managed server runs `mandal`, a Bash tool found in `server/` of the repository and installed to `/opt/mandal` on the server, with `/usr/local/bin/mandal` linked to it. It is the only way the panel reads or changes a server, and it can be used by hand over SSH:

```bash
sudo mandal inventory           # everything on the server, as JSON
sudo mandal app:list            # apps and their manifests
sudo mandal provision --check   # what provisioning would add, and any conflicts
sudo mandal provision --dry-run # print every step without changing anything
sudo mandal provision           # install and harden the stack
mandal version
mandal help
```

Conventions:

- Read commands print JSON, apart from `version` without `--json` and `app:env` or `app:compose` without `--from=`, which print the bare value or file. Write commands print what they do, one `+` line per step, and most accept `--dry-run`.
- Write commands need root. The `mandal` user may run `sudo mandal` and nothing else.
- Only one write command runs at a time; a second one waits up to 30 seconds for the lock (`/run/lock/mandal.lock`) and then gives up. `backup:run` has its own lock and fails at once when a backup is already running; `reboot` takes none.
- Every write command is recorded in `/var/log/mandal/actions.log` with the time, the Unix user and the panel that ran it.
- Configuration files Mandal writes carry a `# managed-by: mandal` line. Files it has to change but did not write are backed up under `/var/backups/mandal/config` first.
- Secrets (database passwords, `.env` contents, compose files) are passed as files, never as arguments.
- The build hash reported by `mandal version --json` and the inventory is a hash of `bin/`, `lib/` and `VERSION`; the panel compares it with its own copy to know when a server needs `cli:update`.

Paths can be overridden with `MANDAL_*` environment variables (`MANDAL_ETC`, `MANDAL_NGINX_DIR`, `MANDAL_HOME_DIR` and so on), which is how the test suite runs against fixture directories.

## Commands

<!--@include: ./cli-commands.md-->

`provision` and `app:create` print a usage text with `--help`; for the others the options are listed above and in each command's header comment in `server/lib/commands/`.

## Roles and drivers

Components are split into roles, each implemented by a driver in `server/lib/roles/`:

| Role | Driver | Provides |
|---|---|---|
| `database` | `mariadb` | MariaDB server and client, databases, users and grants (dumps and restores call `mariadb-dump` and `mariadb` directly) |
| `cache` | `dragonfly` | Dragonfly on 127.0.0.1:6379 with a generated password |
| `js` | `bun` | Bun and `bunx` in `/usr/local/bin` |

The driver for each role is chosen at provisioning and recorded in `/etc/mandal/server.conf`.
