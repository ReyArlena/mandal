# Docker apps

Choose **Docker compose project** in the New app form for anything that is not Laravel, from a web app to a game server. Paste the compose file and any environment variables. Mandal writes them to `/srv/docker/<app>` (`compose.yml` and `.env`), checks the file with `docker compose config` and starts the project with `docker compose up -d`. The compose file runs as root, so it can do anything on the server; only paste files you trust. The form starts from a template that sets `security_opt: [no-new-privileges:true]`.

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
- **Delete**: `docker compose down`, with volumes removed unless you keep the files, then the project directory, its backup settings and local archives, and the nginx site.

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
