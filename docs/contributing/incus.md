# Testing with Incus

The unit tests cover most of the CLI without a server, but anything the fakes stand in for (`useradd`, `sudo`, `apt`, systemd, SFTP uploads, `docker compose`, a real `mandal` run) needs an Ubuntu 26.04 machine. [Incus](https://linuxcontainers.org/incus/) gives you one in seconds: a system container runs full systemd, SSH, ufw and fail2ban, and a VM adds a real kernel for swap, reboot and kernel-update tests. A panel running on your laptop connects to the instance by IP like any real server.

## Installing Incus

On Arch and derivatives:

```bash
sudo pacman -S incus
sudo systemctl enable --now incus.socket incus
sudo usermod -aG incus-admin "$USER"
sudo incus admin init --minimal
sudo usermod -v 1000000-1000999999 -w 1000000-1000999999 root
sudo systemctl restart incus
```

The `usermod -v/-w` line gives root a subordinate UID and GID range for unprivileged containers (`/etc/subuid` and `/etc/subgid`). Log out and in again for the group to apply.

If ufw runs on the host, let containers get DHCP and DNS from the bridge and route out:

```bash
sudo ufw allow in on incusbr0
sudo ufw route allow in on incusbr0
sudo ufw route allow out on incusbr0
```

For VMs, also install the virtualisation packages and the vsock module:

```bash
sudo pacman -S --needed qemu-base edk2-ovmf virtiofsd
sudo modprobe vhost_vsock
echo vhost_vsock | sudo tee /etc/modules-load.d/vhost_vsock.conf
sudo systemctl restart incus
```

Other distributions: see the [Incus installation guide](https://linuxcontainers.org/incus/docs/main/installing/). The rest of this page is the same everywhere.

## A base image

Create one container, install SSH, snapshot it, and copy the snapshot for every test run:

```bash
incus launch images:ubuntu/26.04/cloud mandal-test -c security.nesting=true
incus exec mandal-test -- bash -c 'apt-get update && apt-get install -y openssh-server'
incus snapshot create mandal-test fresh
```

`security.nesting=true` is what lets Docker run inside the container. Ubuntu 26.04 notes: it uses sudo-rs, uutils coreutils and a socket-activated `ssh.socket`; ufw is not preinstalled; while the ondrej PPA has no packages for the release, provisioning uses Ubuntu's own PHP 8.5.

Throwaway box for a run, and its cleanup:

```bash
incus copy mandal-test/fresh mandal-scn && incus start mandal-scn
incus list -f csv -c n,s,4            # names, state, IPs
incus exec mandal-scn -- bash -c '...'
incus delete mandal-scn --force
```

A VM for swap, reboot and kernel tests:

```bash
incus launch images:ubuntu/26.04 mandal-vm --vm -c limits.cpu=2 -c limits.memory=2GiB
```

## Flow 1: a server managed by your local panel

The panel's **Setup command** fetches `setup.sh` from the latest GitHub release, which cannot carry an unreleased change. Push the checkout into the container and run the script from there with `--source`:

```bash
# 1. add the server in the panel (its IP from `incus list`) and copy the --key=... value from Setup command

# 2. push server/ and VERSION, run setup.sh from the local copy
incus exec mandal-scn -- mkdir -p /root/mandal
incus file push -r server mandal-scn/root/mandal/
incus file push VERSION mandal-scn/root/mandal/VERSION
incus exec mandal-scn -- bash /root/mandal/server/setup.sh --source=/root/mandal --key='ssh-ed25519 AAAA... mandal-panel'

# 3. run it again: every step must report that it is already done

# 4. check
incus exec mandal-scn -- bash -c 'cat /home/mandal/.ssh/authorized_keys; cat /etc/sudoers.d/mandal; cat /opt/mandal/VERSION; sudo -u mandal sudo -n /opt/mandal/bin/mandal version --json'
```

Then **Test connection** in the panel, accept the host key, and **Provision**. Watch the output on the Tasks page. To exercise the first-connection CLI push, write a lower number into `/opt/mandal/VERSION` on the box before the connection test; the panel then queues `UpdateCli` and the build hash afterwards must equal `app(App\Mandal\CliArchive::class)->hash()` in tinker.

Without the panel, the CLI can be swapped in by hand:

```bash
tar -C server -czf /tmp/cli.tgz bin lib
incus file push /tmp/cli.tgz mandal-scn/root/cli.tgz
incus file push VERSION mandal-scn/opt/mandal/VERSION
incus exec mandal-scn -- bash -c 'rm -rf /opt/mandal/bin /opt/mandal/lib; tar -xzf /root/cli.tgz -C /opt/mandal'
incus exec mandal-scn -- mandal provision --check
```

## Flow 2: install.sh for a hosted panel

`install.sh` clones the panel and the CLI from a release tag of the public repository, so it only runs end to end against a published release:

```bash
incus exec mandal-scn -- bash -c 'curl -fsSL https://github.com/ReyArlena/mandal/releases/latest/download/install.sh | sudo bash -s -- --domain=panel.test --skip-ssl'
```

Let's Encrypt cannot reach a container, so skip SSL. Run the script a second time and confirm every step reports "already". Point the domain at the container IP through `/etc/hosts` on the host to open the panel. Unreleased changes to the state machine are covered by `server/tests/run.sh`; the pieces can also be run by hand on a provisioned box (`mandal panel:install --domain=... --repo=... --https --branch=<tag>`, `app:deploy`, `php artisan db:seed`, `php artisan mandal:register-host`).

## Flow 3: Docker apps

The fakes cannot stand in for real `docker compose config` output. On a provisioned box, swap in the working-tree CLI as above and drive `mandal` directly:

```bash
incus file push web.yml mandal-scn/root/web.yml     # a service publishing "127.0.0.1:20100:80"
incus exec mandal-scn -- mandal app:create --name=web --type=docker --from=/root/web.yml --proxy --port=20100 --domain=web.example.test
incus exec mandal-scn -- mandal app:create --name=game --type=docker --from=/root/game.yml   # publishes "15637:15637/udp", no proxy
incus exec mandal-scn -- bash -c 'mandal inventory | jq -c ".apps[] | select(.type==\"docker\") | {name, proxy, port, ports}"'
incus exec mandal-scn -- bash -c 'mandal firewall:list | jq -c .docker'
incus exec mandal-scn -- bash -c 'curl -s -H "Host: web.example.test" http://127.0.0.1/'
incus exec mandal-scn -- mandal app:compose --name=game --from=/root/bad.yml   # must keep the old file
incus exec mandal-scn -- mandal app:delete --name=web
```

`traefik/whoami` is a good throwaway image: HTTP on 80, and `app:configure --port=` to move it. A game server with a UDP port, such as Valheim or Enshrouded, exercises the exposed-ports path; it is reachable from the host at the container's IP.

## Scenarios worth repeating before a release

- Apache installed first: `provision --check` reports the conflict and `provision` refuses.
- A hand-made nginx vhost, an extra ufw rule and an existing database all survive provisioning.
- Provision twice: the second run changes nothing.
- On the VM: swap is created, `reboot` comes back, a kernel update shows "reboot required" on the Updates page.
- Not testable locally: Let's Encrypt (use a real VPS, or Pebble) and GitHub webhooks (auto deploy refuses without a public URL).

## Cleanup

Remove what a run created on both sides: `incus delete mandal-scn --force`, and the server's row in the panel (deleting it from the servers list cascades to its tasks). `incus warning delete --all` clears stale Incus warnings.
