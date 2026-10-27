# Databases and backups

## Databases

The **Databases** page lists every MariaDB database with its size and the users who can use it, and every database user with their databases. From there you can:

- create databases and users, and choose which users can use a database (from either side)
- change a user's password
- delete databases and users that no app uses

Users sign in from the server itself (MariaDB listens on 127.0.0.1 only) and get all privileges on their databases. Passwords are uploaded as a file and never appear in commands or logs. An app's own database and user cannot be deleted here, the app always keeps access to its database, and its password stays in the app's `.env`.

MariaDB's `root` authenticates through the Unix socket, so `sudo mariadb` on the server is the way to run anything the panel does not offer.

## Backups

**Backups** is the second tab of the Databases page. It sets:

- a nightly schedule (any cron expression) for dumping every database on the server with `mariadb-dump`, compressed with zstd
- how many copies to keep locally and remotely
- optionally an S3-compatible bucket to upload to: AWS S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO, Hetzner and others

Credentials are written to `/etc/mandal/rclone.conf` on the server, readable only by root; the panel never stores them. Dumps land under `/var/backups/mandal/db/<database>/`, and the run is logged to `/var/log/mandal/backups.log`. When the server hosts the panel, the panel's SQLite database is copied into the same backup set.

**Back up now** backs up every database at once (`mandal backup:run --database=` on the server does a single one). Any backup, local or remote, can be restored into a fresh database next to the live one for checking; restoring over a live database is left to you on purpose.

The files involved are listed under [backups](../reference/server-files#backups).
