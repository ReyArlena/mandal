---
layout: home

hero:
  name: Mandal
  text: Servers managed over SSH
  tagline: A self-hosted panel that provisions Ubuntu servers and runs Laravel and Docker apps on them. The server is always the source of truth.
  actions:
    - theme: brand
      text: Get started
      link: /guide/getting-started
    - theme: alt
      text: Server CLI reference
      link: /reference/cli
    - theme: alt
      text: GitHub
      link: https://github.com/ReyArlena/mandal

features:
  - title: Nothing cached in the panel
    details: Every page reads live state from the server through the mandal CLI. Several panels can manage the same server, and a panel can manage the server it runs on.
  - title: One command per server
    details: A setup command creates a restricted user, installs the panel's key and the CLI. Provisioning installs and hardens nginx, PHP, MariaDB, Dragonfly, Bun, Supervisor, Certbot, Docker, ufw and fail2ban through drop-in files.
  - title: Laravel and Docker apps
    details: Zero-downtime deploys from GitHub with roll back, Supervisor workers, Let's Encrypt, .env editing. Docker apps run from a compose file, with nginx in front when you want it.
  - title: Databases, backups, updates, logs
    details: MariaDB users and databases, nightly dumps to S3-compatible storage, security updates, PHP versions, firewall rules and live log tailing, all from the panel.
---
