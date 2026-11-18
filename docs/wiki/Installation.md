# Installation

Socialize ships as a single Docker image: `ghcr.io/sadjo/socialize`. It contains the web server
(Caddy through FrankenPHP, with automatic HTTPS), the application and its background workers.

## Requirements

- A Linux server (x86-64 or ARM64) with [Docker Engine](https://docs.docker.com/engine/install/) and the Compose plugin.
- 1 GB of RAM minimum, 2 GB recommended (video conversion and Instagram imports are the most demanding tasks).
- Enough disk space for your media: originals are kept alongside their WebP / MP4 copies.
- A domain name pointing to the server, with ports **80** and **443** reachable from the Internet
  (unless a reverse proxy already handles HTTPS — see below).

> [!IMPORTANT]
> Choose your domain name before opening the instance to the fediverse and **never change it afterwards**:
> it is part of every account and post address (`@you@photos.example.org`).

## Option 1 — One-line installer (recommended)

```bash
curl -fsSL https://raw.githubusercontent.com/sadjo/socialize/main/install.sh | sh
```

The installer:

1. checks that Docker and Docker Compose are available;
2. asks for the domain name, then writes `~/socialize/docker.env` with a freshly generated `APP_SECRET`;
3. writes `~/socialize/compose.yaml` (SQLite, or MariaDB with `--db mariadb`);
4. pulls the image and starts the instance, waiting until it is healthy;
5. creates the administrator account (password prompted, or generated and displayed once).

Every question can be answered on the command line, which makes the installer usable in scripts:

```bash
curl -fsSL https://raw.githubusercontent.com/sadjo/socialize/main/install.sh | sh -s -- \
  --domain photos.example.org \
  --db mariadb \
  --email admin@example.org --username admin \
  --yes
```

| Option | Default | Description |
| --- | --- | --- |
| `--domain NAME` | prompted | Public domain name |
| `--dir PATH` | `~/socialize` | Installation directory |
| `--db sqlite\|mariadb` | `sqlite` | Database engine |
| `--behind-proxy` | off | Serve plain HTTP for an existing reverse proxy |
| `--http-port PORT` | `80` (`8080` behind a proxy) | HTTP port published on the host |
| `--version TAG` | `latest` | Image tag, e.g. `v0.9.15` |
| `--email`, `--username`, `--password` | prompted | Administrator account |
| `--yes` | off | Never ask questions |

Running the installer again on an existing installation keeps `docker.env`, the database password and all data,
and upgrades the containers.

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

Keep the generated `APP_SECRET`: it encrypts the private keys of the accounts. Then create the administrator:

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
APP_SECRET=<output of: openssl rand -hex 32>
REGISTRATION=invite
```

```bash
docker compose up -d
docker compose exec app php bin/console app:user:create admin@example.org admin --admin
```

The repository also contains a ready-made [`compose.yaml`](https://github.com/sadjo/socialize/blob/main/compose.yaml),
[`compose.mariadb.yaml`](https://github.com/sadjo/socialize/blob/main/compose.mariadb.yaml) and
[`docker.env.example`](https://github.com/sadjo/socialize/blob/main/docker.env.example) to build the
image from source (`docker compose build`).

### With MariaDB

Add a MariaDB service and point `DATABASE_URL` to it:

```yaml
services:
  app:
    environment:
      DATABASE_URL: "mysql://socialize:CHANGE_ME@mariadb:3306/socialize?serverVersion=11.4.0-MariaDB&charset=utf8mb4"
    depends_on:
      mariadb: { condition: service_healthy }
  mariadb:
    image: mariadb:11.4
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: socialize
      MARIADB_USER: socialize
      MARIADB_PASSWORD: CHANGE_ME
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      retries: 20
    volumes: [mariadb:/var/lib/mysql]
volumes:
  mariadb:
```

An existing MySQL 8+ or MariaDB 10.11+ server works too: set `DATABASE_URL` in `docker.env` and always include
`serverVersion`.

## Behind a reverse proxy

When Traefik, Nginx, Caddy or another proxy already terminates HTTPS:

```bash
SERVER_NAME=:80                         # plain HTTP inside the container
DEFAULT_URI=https://photos.example.org  # public address
TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR   # trust the proxy's X-Forwarded-* headers
```

Publish only port 80 (for example `8080:80`) and forward the domain to it. The proxy must pass the `Host` and
`X-Forwarded-Proto` headers and allow large request bodies (Instagram archives are uploaded in 50 MB chunks).

Nginx example:

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

## First steps

1. Open `https://photos.example.org` and log in with the administrator account.
2. Fill in your profile (Settings), then the instance block (Administration › Instance).
3. Invite people from Administration › Invitations, or create their accounts directly.
4. Grant the **Publish** permission to the accounts that may post (administrators always can).

The `/healthz` endpoint returns the status of the instance as JSON, for your monitoring.

Next: [Configuration](Configuration) · [Upgrade and Backup](Upgrade-and-Backup)
