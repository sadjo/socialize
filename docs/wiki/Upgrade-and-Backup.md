# Upgrade and Backup

## Upgrading

Socialize follows [semantic versioning](https://semver.org): each release is published as
`ghcr.io/sadjo/socialize:vX.Y.Z` and `:latest`. Release notes are on the
[releases page](https://github.com/sadjo/socialize/releases).

```bash
cd ~/socialize
docker compose pull
docker compose up -d
```

Database migrations run automatically when the container starts. With SQLite, a copy of the database is made just
before (`/data/socialize.db.YYYYMMDD-HHMMSS.bak`); you may delete old copies once the upgrade is confirmed.

After upgrading from a version older than 0.9.16, build the responsive image sizes of existing media once:
`docker compose exec app php bin/console app:media:complete-sizes`.

Since v0.9.25, images received from the fediverse are copied to the instance (in several sizes, like your own).
For those received earlier, run once: `docker compose exec app php bin/console app:media:cache-remote`.

Since v0.9.37, text posts show a preview of their first link. For those already present, run once:
`docker compose exec app php bin/console app:link-previews:fetch`.

To pin a version, replace `latest` with a tag in `compose.yaml` (`image: ghcr.io/sadjo/socialize:v0.9.15`).
Re-running the installer with `--version vX.Y.Z` does the same.

## Backing up

Back up three things:

1. the `docker.env` file (and `mariadb.env` if present) — **especially `APP_SECRET`**;
2. the database;
3. the `media` volume.

### SQLite

Stop the application for a consistent copy (a few seconds of downtime):

```bash
cd ~/socialize
docker compose stop app
docker run --rm -v socialize_data:/data -v "$PWD":/backup alpine \
  tar czf /backup/socialize-data-$(date +%F).tar.gz -C /data .
docker compose start app
```

### MariaDB

```bash
docker compose exec mariadb sh -c 'mariadb-dump -u socialize -p"$MARIADB_PASSWORD" socialize' \
  | gzip > socialize-db-$(date +%F).sql.gz
```

### Media

```bash
docker run --rm -v socialize_media:/media -v "$PWD":/backup alpine \
  tar czf /backup/socialize-media-$(date +%F).tar.gz -C /media .
```

For large libraries, prefer an incremental tool (restic, borg, rsync) pointed at the volume directory
(`docker volume inspect socialize_media`).

## Restoring

1. Install Socialize with the same `docker.env` (same `APP_SECRET` and `DEFAULT_URI`).
2. Stop the application: `docker compose stop app`.
3. Restore the volumes (`tar xzf … -C /data`, `-C /media`) or import the SQL dump into MariaDB.
4. Start again: `docker compose up -d`.

## Moving to another server

Copy `~/socialize` and both volumes, keep the same domain name, and update the DNS record. Federation keeps
working because addresses do not change.

## Switching from SQLite to MariaDB

Socialize does not migrate data between engines automatically. Use a fresh instance with MariaDB for new
installations; for an existing SQLite instance, keep SQLite unless you need several worker containers.
