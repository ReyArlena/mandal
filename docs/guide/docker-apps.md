# Docker apps

Choose the **Docker** tab in the New app form for anything that is not Laravel, from a web app to a game server. Paste the compose file and any environment variables. Mandal writes them to `/srv/docker/<app>` (`compose.yml` and `.env`), and checks the file with `docker compose config`. Nothing starts yet: press **Start** on the app page when the files and environment look right, and the first start pulls the images in its own task, with the download shown as it goes. When it succeeds, **Follow container logs** opens the containers' output with live refresh on, for servers that keep setting up after they start. Containers are named `<app>-<service>-1` after the app, so the same compose file can be used for more than one app; a file that sets `container_name` is refused. The form checks the name, domain and compose file as you type and keeps **Create app** disabled while one is wrong: a name an app, compose project or (for Laravel) database already uses, a reserved system name, or a domain (with or without www.) an nginx site on the server already answers to. The compose file runs as root, so it can do anything on the server; only paste files you trust. The form starts from a template that sets `security_opt: [no-new-privileges:true]`.

## Importing a backup

**Import backup (optional)**, folded under the name field, takes a `.zip` or `.tar.gz` of a project folder, up to the size shown under the field: the smallest of the panel's own PHP `upload_max_filesize` and `post_max_size`, nginx's `client_max_body_size` when the panel manages the server it runs on, and its import limit (1 GB by default). The archive goes to the panel, so it is the panel's limits that count, not the target server's. The archive is checked as soon as it arrives: only files and folders, no links or paths leaving the folder, at most 20 GB unpacked, and a compose file at the top or inside a single top-level folder, found under Compose's own names (`compose.yaml`, `compose.yml`, `docker-compose.yaml`, `docker-compose.yml`). Its contents fill the compose field and are saved as `compose.yml`; a `.env` fills the environment variables, and the name is suggested from the file name. Creating the app packs the remaining files, uploads them with the compose file (progress in the task output) and unpacks them into `/srv/docker/<app>` before the containers start. The files belong to root with their modes kept, so an image that runs as another user cannot write to them. Hand them over in the compose file with a one-off service that the app waits for; the default compose file has a commented example:

```yaml
services:
  fix-permissions:
    image: busybox:1.37
    command: chown -R 10000:10000 /savegame
    volumes:
      - ./savegame:/savegame
    restart: "no"
  game:
    image: sknnr/enshrouded-dedicated-server:latest
    depends_on:
      fix-permissions:
        condition: service_completed_successfully
    volumes:
      - ./savegame:/home/steam/enshrouded/savegame
```

It runs before every start, so ownership is put right again after a restore or a change.

When the panel runs on the server, the upload also passes nginx (`client_max_body_size`) and that server's PHP-FPM limits; raise them on the **PHP** page for larger archives. `MANDAL_IMPORT_MAX_UPLOAD` and `MANDAL_IMPORT_MAX_UNPACKED` in the panel's `.env` change its own limits.

## With or without nginx

Tick **Serve a domain through nginx** for HTTP apps. Give the domain and the port a service publishes on `127.0.0.1` (for example `127.0.0.1:20000:80` in `ports:`), and nginx proxies the domain to it with websocket support. SSL works as for Laravel apps. The port must be a loopback port the compose file actually publishes; Mandal checks before writing the site.

Leave it off for game servers and other non-HTTP services. They publish their own ports and have no domain.

## Ports and the firewall

Docker publishes ports straight past ufw: anything in `ports:` not bound to `127.0.0.1` is reachable from the internet whatever the firewall rules say. The app page lists the published ports, and the **Firewall** page has a Docker section listing every port containers expose past the rules, read from `docker ps`.

## The app page

- **Compose file**: edit and apply. An invalid file is refused and the current one kept. If a proxied app's loopback port moves to another port in the new file, nginx follows it when there is exactly one candidate; otherwise the file is refused with a message to change the port first.
- **Upstream port**: moves nginx to another loopback port the file publishes (`mandal app:configure --port=`).
- **Start**, **Stop**, **Restart**: `docker compose up -d`, `stop` and `restart`. Stopping waits for a clean shutdown and keeps container state.
- **Pull and restart**: `docker compose pull` then `up -d --remove-orphans`, so the latest images are used. Pull progress is shown in the task output.
- **Logs**: opens the Logs page on the container output.
- **Backups**: archives of the project directory, set up as for [Laravel apps](./apps#backups). **Pause containers while archiving** (on by default) runs `docker compose pause` for the duration so files are consistent; the app is unreachable meanwhile. Set it up once the containers are running.
- **Delete**: `docker compose down`, the nginx site and its certificate, the backup settings and the images no other container uses (they can be pulled again). Unless you keep the files, the named volumes, the project directory, its local archives and the nginx logs go too. Kept files stay in `/srv/docker/<app>`, bind mounts such as `./data` included, and an app created later with the same name uses that folder again.

## Example: a game server

```yaml
services:
  game:
    image: lloesche/valheim-server
    restart: unless-stopped
    security_opt:
      - no-new-privileges:true
    ports:
      - "2456-2457:2456-2457/udp"
    env_file: .env
    volumes:
      - config:/config
      - data:/opt/valheim

volumes:
  config:
  data:
```

Create it without the nginx option. The Firewall page then lists `2456-2457/udp` as exposed by Docker, and players connect to the server's address on 2456.

### Windows game servers and ntsync

Some game servers only ship for Windows, and their images run them under Proton or Wine (Enshrouded, for example). They spend much of their time on Windows locks and events, which the kernel's ntsync driver handles far faster than Wine's fallbacks. It needs Linux 6.14 or newer with the ntsync module: Ubuntu 26.04 has it, Ubuntu 24.04's standard 6.8 kernel does not, and container-based VPSes (LXC, OpenVZ) cannot load kernel modules at all.

Press **Enable** next to ntsync on the Server tab (`mandal ntsync:enable`), which loads the driver and lists it in `/etc/modules-load.d/ntsync.conf` for every boot. Then pass the device to the game's service and apply the compose file:

```yaml
    devices:
      - /dev/ntsync:/dev/ntsync
```

The device is readable and writable by every user, as the kernel creates it; it only lets a process make its own locks and events. Images based on a recent GE-Proton use it on their own, and some log `ntsync: up and running.` at start.

### A page for your players

**Settings > Server details pages** (the **Mandal** tab above the servers list) makes public pages that tell players how to join your game servers. Each page has an optional title and intro, then one card per game server with its name, an optional game version, the address and an optional password, each with a copy button. The password is shown as written, so a note such as "must be added to whitelist" can go there instead.

**Status** is hidden, set by hand (**Online** or **Paused**) or follows a Docker container. Following a container, chosen from one list of every container on your connected servers shown as `container (server)`, the card shows **Online** while it runs, **Paused** when it is stopped and **Unknown** when the server could not be reached or the container is gone. For a game with a one-off setup service, choose the game's own container.

**Remove branding** and **Remove credit** leave out the Mandal logo and the credit line at the bottom. Pages are served at `/p/<address>` without signing in. A generated address is the page name followed by a random part, so it cannot be guessed; you can also type your own. Pages are not indexed by search engines.

A container's status is stored, not fetched for each visitor: the panel updates it whenever it reads that server (opening its Server or Apps page) and after every task on it, such as Start or Stop. A page never connects to a server, so visitors reload it to see a change you made in the panel, and a container that stops on its own (a crash, a reboot) shows as running until the panel next reads that server.

## Example: a web app behind nginx

```yaml
services:
  web:
    image: traefik/whoami
    restart: unless-stopped
    security_opt:
      - no-new-privileges:true
    ports:
      - "127.0.0.1:20000:80"
```

Tick the nginx option, give a domain and port `20000`. Request a certificate from the app page once the domain points at the server.

The files involved are listed under [Docker apps](../reference/server-files#docker-apps).
