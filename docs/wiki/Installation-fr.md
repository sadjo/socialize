# Installation

Socialize est livré sous forme d'une seule image Docker : `ghcr.io/sadjo/socialize`. Elle contient le
serveur web (Caddy via FrankenPHP, HTTPS automatique), l'application et ses tâches de fond.

## Prérequis

- Un serveur Linux (x86-64 ou ARM64) avec [Docker Engine](https://docs.docker.com/engine/install/) et le plugin Compose.
- 1 Go de mémoire au minimum, 2 Go conseillés (conversion des vidéos et import Instagram sont les plus gourmands).
- De l'espace disque pour les médias : les originaux sont gardés à côté de leurs copies WebP / MP4.
- Un nom de domaine qui pointe vers le serveur, ports **80** et **443** joignables depuis Internet (sauf si un reverse
  proxy gère déjà le HTTPS, voir plus bas).

> [!IMPORTANT]
> Choisissez le nom de domaine avant d'ouvrir l'instance au fédivers et **ne le changez plus** : il fait partie de
> l'adresse de chaque compte et de chaque publication (`@vous@photos.example.org`).

## Option 1 — Installation en une ligne (conseillée)

```bash
curl -fsSL https://raw.githubusercontent.com/sadjo/socialize/main/install.sh | sh
```

Le script :

1. vérifie que Docker et Docker Compose sont disponibles ;
2. demande le nom de domaine, puis écrit `~/socialize/docker.env` avec un `APP_SECRET` généré ;
3. écrit `~/socialize/compose.yaml` (SQLite, ou MariaDB avec `--db mariadb`) ;
4. télécharge l'image et démarre l'instance en attendant qu'elle soit prête ;
5. crée le compte administrateur (mot de passe demandé, ou généré et affiché une fois).

Toutes les réponses peuvent être données en ligne de commande :

```bash
curl -fsSL https://raw.githubusercontent.com/sadjo/socialize/main/install.sh | sh -s -- \
  --domain photos.example.org \
  --db mariadb \
  --email admin@example.org --username admin \
  --yes
```

| Option | Par défaut | Rôle |
| --- | --- | --- |
| `--domain NOM` | demandé | Nom de domaine public |
| `--dir CHEMIN` | `~/socialize` | Dossier d'installation |
| `--db sqlite\|mariadb` | `sqlite` | Base de données |
| `--behind-proxy` | non | HTTP simple pour un reverse proxy existant |
| `--http-port PORT` | `80` (`8080` derrière un proxy) | Port HTTP publié sur l'hôte |
| `--version TAG` | `latest` | Version de l'image, par exemple `v0.9.15` |
| `--email`, `--username`, `--password` | demandés | Compte administrateur |
| `--yes` | non | Aucune question |

Relancé sur une installation existante, le script garde `docker.env`, le mot de passe de la base et les données, et
met à jour les conteneurs.

## Option 2 — `docker run`

```bash
docker run -d --name socialize --restart unless-stopped \
  -p 80:80 -p 443:443 -p 443:443/udp \
  -e SERVER_NAME=photos.example.org \
  -e DEFAULT_URI=https://photos.example.org \
  -e APP_SECRET="$(openssl rand -hex 32)" \
  -v socialize_data:/data -v socialize_media:/media \
  ghcr.io/sadjo/socialize:latest
```

Conservez l'`APP_SECRET` généré : il chiffre les clés privées des comptes. Créez ensuite l'administrateur :

```bash
docker exec -it socialize php bin/console app:user:create admin@example.org admin --admin
```

## Option 3 — Docker Compose

```yaml
# compose.yaml
name: socialize
services:
  app:
    image: ghcr.io/sadjo/socialize:latest
    restart: unless-stopped
    init: true
    env_file: [docker.env]
    ports: ["80:80", "443:443", "443:443/udp"]
    volumes:
      - data:/data
      - media:/media
volumes:
  data:
  media:
```

```bash
# docker.env
SERVER_NAME=photos.example.org
DEFAULT_URI=https://photos.example.org
APP_SECRET=<résultat de : openssl rand -hex 32>
REGISTRATION=invite
```

```bash
docker compose up -d
docker compose exec app php bin/console app:user:create admin@example.org admin --admin
```

Le dépôt contient aussi [`compose.yaml`](https://github.com/sadjo/socialize/blob/main/compose.yaml),
[`compose.mariadb.yaml`](https://github.com/sadjo/socialize/blob/main/compose.mariadb.yaml) et
[`docker.env.example`](https://github.com/sadjo/socialize/blob/main/docker.env.example) pour construire
l'image depuis les sources (`docker compose build`).

### Avec MariaDB

```yaml
services:
  app:
    environment:
      DATABASE_URL: "mysql://socialize:A_CHANGER@mariadb:3306/socialize?serverVersion=11.4.0-MariaDB&charset=utf8mb4"
    depends_on:
      mariadb: { condition: service_healthy }
  mariadb:
    image: mariadb:11.4
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: socialize
      MARIADB_USER: socialize
      MARIADB_PASSWORD: A_CHANGER
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      retries: 20
    volumes: [mariadb:/var/lib/mysql]
volumes:
  mariadb:
```

Un serveur MySQL 8+ ou MariaDB 10.11+ existant convient aussi : renseignez `DATABASE_URL` dans `docker.env`, toujours
avec `serverVersion`.

## Derrière un reverse proxy

Quand Traefik, Nginx, Caddy ou un autre proxy gère déjà le HTTPS :

```bash
SERVER_NAME=:80                         # HTTP simple dans le conteneur
DEFAULT_URI=https://photos.example.org  # adresse publique
TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR   # en-têtes X-Forwarded-* du proxy acceptés
```

Publiez seulement le port 80 (par exemple `8080:80`) et faites-y suivre le domaine. Le proxy doit transmettre les
en-têtes `Host` et `X-Forwarded-Proto` et accepter des requêtes volumineuses (archives Instagram envoyées par
morceaux de 50 Mo).

```nginx
server {
    server_name photos.example.org;
    client_max_body_size 64m;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    # listen 443 ssl; ssl_certificate …;
}
```

## Premiers pas

1. Ouvrez `https://photos.example.org` et connectez-vous avec le compte administrateur.
2. Complétez votre profil (Réglages), puis le bloc Instance (Administration › Instance).
3. Invitez des personnes (Administration › Invitations) ou créez directement leurs comptes.
4. Accordez le droit **Publication** aux comptes qui publieront (toujours acquis aux administrateurs).

La sonde `/healthz` renvoie l'état de l'instance au format JSON, pour votre supervision.

Ensuite : [Configuration](Configuration-fr) · [Mise à jour et sauvegarde](Mise-a-jour-et-sauvegarde)
