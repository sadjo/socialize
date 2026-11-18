# Mise à jour et sauvegarde

## Mettre à jour

Socialize suit le [versionnage sémantique](https://semver.org/lang/fr/) : chaque version est publiée sous
`ghcr.io/sadjo/socialize:vX.Y.Z` et `:latest`. Les notes de version sont sur la
[page des releases](https://github.com/sadjo/socialize/releases).

```bash
cd ~/socialize
docker compose pull
docker compose up -d
```

Les migrations de la base s'appliquent au démarrage du conteneur. Avec SQLite, une copie de la base est faite juste
avant (`/data/socialize.db.AAAAMMJJ-HHMMSS.bak`) ; supprimez les anciennes copies une fois la mise à jour validée.

Après une mise à jour depuis une version antérieure à 0.9.16, fabriquez une fois les tailles d'images des médias
existants : `docker compose exec app php bin/console app:media:complete-sizes`.

Depuis la v0.9.25, les images reçues du fédivers sont copiées sur l'instance (en plusieurs tailles, comme les
vôtres). Pour celles reçues avant, lancez une fois : `docker compose exec app php bin/console app:media:cache-remote`.

Pour figer une version, remplacez `latest` par une étiquette dans `compose.yaml`
(`image: ghcr.io/sadjo/socialize:v0.9.15`), ou relancez le script d'installation avec `--version vX.Y.Z`.

## Sauvegarder

Trois éléments à sauvegarder :

1. le fichier `docker.env` (et `mariadb.env` s'il existe) — **surtout `APP_SECRET`** ;
2. la base de données ;
3. le volume `media`.

### SQLite

Arrêtez l'application le temps d'une copie cohérente (quelques secondes) :

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

### Médias

```bash
docker run --rm -v socialize_media:/media -v "$PWD":/backup alpine \
  tar czf /backup/socialize-media-$(date +%F).tar.gz -C /media .
```

Pour une grande photothèque, préférez un outil incrémental (restic, borg, rsync) sur le dossier du volume
(`docker volume inspect socialize_media`).

## Restaurer

1. Installez Socialize avec le même `docker.env` (même `APP_SECRET` et même `DEFAULT_URI`).
2. Arrêtez l'application : `docker compose stop app`.
3. Restaurez les volumes (`tar xzf … -C /data`, `-C /media`) ou importez la sauvegarde SQL dans MariaDB.
4. Redémarrez : `docker compose up -d`.

## Changer de serveur

Copiez `~/socialize` et les deux volumes, gardez le même nom de domaine et mettez à jour l'enregistrement DNS. La
fédération continue de fonctionner car les adresses ne changent pas.

## Passer de SQLite à MariaDB

Socialize ne migre pas les données d'un moteur à l'autre. Choisissez MariaDB dès l'installation si vous prévoyez
plusieurs conteneurs de workers ; sinon gardez SQLite.
