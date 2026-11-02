# Databases and backups

## Databases

The **Databases** page lists every MariaDB database with its size and the users who can use it, and every database user with their databases. From there you can:

- create databases and users, and choose which users can use a database (from either side)
- change a user's password
- import and export databases
- delete databases and users that no app uses

Users sign in from the server itself (MariaDB listens on 127.0.0.1 only) and get all privileges on their databases. Passwords are uploaded as a file and never appear in commands or logs. An app's own database and user cannot be deleted here, the app always keeps access to its database, and its password stays in the app's `.env`.

MariaDB's `root` authenticates through the Unix socket, so `sudo mariadb` on the server is the way to run anything the panel does not offer.

### Import, export and backup

**Export** on a database downloads a dump, as plain `.sql` or compressed `.sql.zst`, made the same way as the backups (`mariadb-dump --single-transaction --routines --triggers --events`). The file is named like a backup, `<database>-<timestamp>.sql`.

**Backup** on a database takes one backup of it now (`backup:run --database=`), to the destinations set under Backups below, and lists it with the scheduled ones. It counts towards the number of backups kept, so the oldest may be removed. It is greyed out until backups are set up.

**Import** takes a `.sql` dump, or one compressed with gzip (`.sql.gz`) or zstd (`.sql.zst`), so backups and exports from here import as they are. Nothing is backed up first: a warning in the form says the data will be overwritten, so export or back up the database first if you want to keep it, and stop the app or put it in maintenance mode while it runs. **Empty the database first** drops every table before the import; without it the dump runs on top of the current tables, which suits most dumps since they drop and recreate the tables they contain. The import runs as MariaDB's `root`, so a dump that switches to or creates another database (`USE`, `CREATE DATABASE`, as `mariadb-dump --databases` writes) is refused; export a single database instead. The upload limit is the panel's own (shown in the form).

## Backups

**Backups** is the second tab of the Databases page. Every database on the server is dumped with `mariadb-dump` and compressed with zstd; when the server hosts the panel, the panel's SQLite database is copied into the same set. A freshly provisioned server already keeps a nightly local copy (02:30 UTC, seven copies); **Settings** changes that:

- **Enable backups** switches the schedule on or off. **Back up now** works either way.
- **Local** keeps copies under `/var/backups/mandal/db/<database>/` on the server, with its own frequency (hourly, every 6 hours, daily, weekly, or any cron line) and number of copies to keep.
- **Remote** uploads to one of the [backup destinations](#backup-destinations) from Settings, with its own frequency and retention, into an optional subfolder. The form shows the full `s3://` path the files go to.

The run is logged to `/var/log/mandal/backups.log`, and the page shows the last run. Any backup, local or remote, can be restored. Left empty, **Restore into database** loads it into a fresh database next to the live one (`<database>_restore_<timestamp>`) for checking; it is an ordinary database from then on, backed up with the others until you delete it. The field also lists the server's databases and takes a new name. Picking an existing database empties it and loads the backup, with the same warning as Import and no backup first; its users keep their access. Restores never create users or change access, so give a new database's user access on the Databases page. **Delete** removes one copy.

The panel checks every server hourly and raises a notification when a scheduled run failed. The files involved are listed under [backups](../reference/server-files#backups).

### Backup destinations

Destinations are S3-compatible buckets (AWS S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO, Hetzner and others) added once under **Settings > Backup destinations**, with an endpoint, bucket, optional path prefix, region and keys. The keys are stored encrypted in the panel and copied to a server only when a backup there starts using the destination; the server then keeps its own rclone config under `/etc/mandal/rclone.d/`, readable only by root. The local disk is always available and needs no setup.

Removing a destination switches every server off that remote; a backup that had no other destination is disabled. Files already in the bucket stay.

::: tip After updating
Servers set up before destinations moved to Settings keep their old backup files but no longer run on a schedule. Open the Backups page and save the settings again, choosing a destination from Settings if you used a bucket before.
:::
