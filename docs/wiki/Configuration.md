# Configuration

Socialize is configured with environment variables, usually in the `docker.env` file next to `compose.yaml`.
Restart the container after a change: `docker compose up -d`.

## Essential settings

| Variable | Default | Description |
| --- | --- | --- |
| `APP_SECRET` | — (**required**) | Application secret, e.g. `openssl rand -hex 32`. Encrypts the account keys and connection tokens: **never change it** on a running instance |
| `SERVER_NAME` | `localhost` | Domain served by Caddy, which obtains the HTTPS certificate automatically. `:80` for plain HTTP behind a proxy |
| `DEFAULT_URI` | `http://localhost` | Public URL of the instance, used for links generated outside HTTP requests and for every ActivityPub address. Choose it once and keep it |
| `DATABASE_URL` | `sqlite:////data/socialize.db` | Database. MariaDB/MySQL: `mysql://user:pass@host:3306/socialize?serverVersion=11.4.0-MariaDB&charset=utf8mb4` |
| `TRUSTED_PROXIES` | empty | Reverse proxies whose `X-Forwarded-*` headers are trusted, e.g. `127.0.0.1,REMOTE_ADDR` |

## Accounts and access

| Variable | Default | Description |
| --- | --- | --- |
| `REGISTRATION` | `invite` | `open`: anyone can sign up · `invite`: only the first account (the administrator) and invited people · `closed`: nobody; create accounts with the console or the administration |
| `HOME_LOGIN_ONLY` | `1` | `1`: visitors only see the login form; public feeds, search and sign-up are closed to them. `0`: public home page and feeds |
| `MASTODON_LOGIN` | `closed` | Sign-in with a Mastodon account: `closed`, `open` (any server) or a comma-separated list of allowed servers (`mastodon.social,piaille.fr`) |
| `MASTODON_SIGNUP` | empty | Server on which people can create a Mastodon account from the login screen (e.g. `mastodon.social`); it must also be allowed by `MASTODON_LOGIN` |

See [Mastodon Sign-In](Mastodon-Sign-In) for details.

## E-mail

E-mails are used for invitations.

| Variable | Default | Description |
| --- | --- | --- |
| `MAILER_DSN` | `null://null` (nothing sent) | Symfony Mailer DSN, e.g. `smtp://user:password@smtp.example.org:587`. URL-encode special characters in the user name and password |
| `MAILER_FROM` | `Socialize <ne-pas-repondre@localhost>` | Sender, e.g. `Socialize <no-reply@example.org>`; the address must be accepted by your mail server |

Without a mail server, invitations can still be shared as links.

## Background work

Socialize processes videos, federation deliveries, imports and notifications in the background with Symfony
Messenger; the queues are stored in the database, no extra service is required.

| Variable | Default | Description |
| --- | --- | --- |
| `WORKERS` | `1` | Number of Messenger consumers started inside the web container (`0` for none) |
| `MESSENGER_MEMORY_LIMIT` | `512M` | Memory limit of each worker (Instagram archive analysis needs ~512 MB) |
| `MESSENGER_TRANSPORTS` | `federation async media` | Space-separated queues consumed by a worker, in priority order. Worker number 1 (`WORKER_ID=1`) also runs the scheduler (`scheduler_default`) |

### Dedicated workers

For busy instances, set `WORKERS=0` on the web container and run workers in separate containers from the same
image (`command: worker`):

```yaml
services:
  worker-federation:
    image: ghcr.io/sadjo/socialize:latest
    command: worker
    env_file: [docker.env]
    environment: { WORKER_ID: 1 }   # federation, background tasks and scheduler
    volumes: [data:/data, media:/media]
  worker-media:
    image: ghcr.io/sadjo/socialize:latest
    command: worker
    env_file: [docker.env]
    environment: { MESSENGER_TRANSPORTS: media, WORKER_ID: 2 }   # video conversion only
    volumes: [data:/data, media:/media]
```

Workers wait for the database and for the migrations applied by the web container. Use MariaDB/MySQL when
several containers share the database.

## Instagram (optional)

| Variable | Default | Description |
| --- | --- | --- |
| `INSTAGRAM_APP_ID` | empty | Default Meta application of the instance (each account can also set its own) |
| `INSTAGRAM_APP_SECRET` | empty | Its secret; keep it out of version control |
| `INSTAGRAM_CONFIG_ID` | empty | Facebook Login for Business configuration id (recent Meta applications) |

See [Integrations › Instagram crossposting](Integrations#crossposting-to-instagram).

## Advanced

| Variable | Default | Description |
| --- | --- | --- |
| `STORAGE_DIR` | `/media` in the image | Where originals and converted media are stored |
| `SQLITE_FOREIGN_KEYS` | `1` | SQLite foreign keys (automatically disabled during migrations) |
| `FEDERATION_ALLOW_PRIVATE_NETWORKS` | `0` | Allow federation with private network addresses. **Local testing only** |
| `APP_VERSION` | image tag | Version displayed at the bottom of the menu |
| `HTTP_PORT`, `HTTPS_PORT` | `80`, `443` | Host ports published by the repository's `compose.yaml` |

## Web Push

Push notifications need no configuration: the VAPID keys of the instance are generated on first use and stored
encrypted with `APP_SECRET`. They require HTTPS. On iPhone and iPad, notifications only work once the site has been
added to the home screen.

## Volumes

| Path | Content |
| --- | --- |
| `/data` | SQLite database (and its automatic backups before migrations), application keys |
| `/media` | Originals (private, with metadata) and public copies (WebP, MP4, posters) |

Both volumes must be backed up: see [Upgrade and Backup](Upgrade-and-Backup).
