# Configuration

Socialize se règle par variables d'environnement, en général dans le fichier `docker.env` à côté de `compose.yaml`.
Relancez le conteneur après un changement : `docker compose up -d`.

## Réglages essentiels

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `APP_SECRET` | — (**obligatoire**) | Secret de l'application (`openssl rand -hex 32`). Chiffre les clés des comptes et les jetons de connexion : **ne le changez jamais** sur une instance en service |
| `SERVER_NAME` | `localhost` | Domaine servi par Caddy, qui obtient le certificat HTTPS automatiquement. `:80` pour du HTTP simple derrière un proxy |
| `DEFAULT_URI` | `http://localhost` | Adresse publique de l'instance, utilisée hors requête HTTP et pour toutes les adresses ActivityPub. À choisir une fois pour toutes |
| `DATABASE_URL` | `sqlite:////data/socialize.db` | Base de données. MariaDB/MySQL : `mysql://user:pass@hote:3306/socialize?serverVersion=11.4.0-MariaDB&charset=utf8mb4` |
| `TRUSTED_PROXIES` | vide | Reverse proxies dont les en-têtes `X-Forwarded-*` sont acceptés, par exemple `127.0.0.1,REMOTE_ADDR` |

## Comptes et accès

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `REGISTRATION` | `invite` | `open` : inscription libre · `invite` : seul le premier compte (administrateur) et les personnes invitées · `closed` : aucune ; comptes créés par la console ou l'administration |
| `HOME_LOGIN_ONLY` | `1` | `1` : les visiteurs ne voient que le formulaire de connexion ; fils publics, recherche et inscription leur sont fermés. `0` : accueil et fils publics |
| `MASTODON_LOGIN` | `closed` | Connexion avec un compte Mastodon : `closed`, `open` (tout serveur) ou liste des serveurs permis (`mastodon.social,piaille.fr`) |
| `MASTODON_SIGNUP` | vide | Serveur sur lequel créer un compte Mastodon depuis l'écran de connexion (`mastodon.social`) ; il doit aussi être permis par `MASTODON_LOGIN` |

Voir [Connexion Mastodon](Connexion-Mastodon).

## E-mails

Les e-mails servent aux invitations.

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `MAILER_DSN` | `null://null` (aucun envoi) | DSN de Symfony Mailer, utilisé pour les invitations et les liens « mot de passe oublié » (sans lui, ces liens ne peuvent pas être reçus), par exemple `smtp://utilisateur:motdepasse@smtp.example.org:587`. Encodez les caractères spéciaux de l'identifiant et du mot de passe |
| `MAILER_FROM` | `Socialize <ne-pas-repondre@localhost>` | Expéditeur, par exemple `Socialize <bonjour@example.org>`, accepté par le serveur d'envoi |

Sans serveur d'envoi, une invitation se partage aussi sous forme de lien.

## Tâches de fond

Vidéos, livraisons au fédivers, imports et notifications passent par Symfony Messenger ; les files sont stockées
dans la base, sans service supplémentaire.

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `WORKERS` | `1` | Nombre de consommateurs lancés dans le conteneur web (`0` pour aucun) |
| `MESSENGER_MEMORY_LIMIT` | `512M` | Mémoire de chaque consommateur (l'analyse d'une archive Instagram demande ~512 Mo) |
| `MESSENGER_TRANSPORTS` | `federation async media` | Files traitées par un consommateur, par ordre de priorité. Le consommateur n° 1 (`WORKER_ID=1`) exécute aussi le planificateur (`scheduler_default`) |

### Workers séparés

Pour une instance chargée, mettez `WORKERS=0` sur le conteneur web et lancez des consommateurs dans d'autres
conteneurs de la même image (`command: worker`) :

```yaml
services:
  worker-federation:
    image: ghcr.io/sadjo/socialize:latest
    command: worker
    env_file: [docker.env]
    environment: { WORKER_ID: 1 }   # fédération, tâches de fond et planificateur
    volumes: [data:/data, media:/media]
  worker-media:
    image: ghcr.io/sadjo/socialize:latest
    command: worker
    env_file: [docker.env]
    environment: { MESSENGER_TRANSPORTS: media, WORKER_ID: 2 }   # conversion des vidéos seulement
    volumes: [data:/data, media:/media]
```

Les workers attendent la base et les migrations appliquées par le conteneur web. Utilisez MariaDB/MySQL quand
plusieurs conteneurs partagent la base.

## Instagram (facultatif)

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `INSTAGRAM_APP_ID` | vide | Application Meta par défaut de l'instance (chaque compte peut aussi régler la sienne) |
| `INSTAGRAM_APP_SECRET` | vide | Sa clé secrète, à garder hors du dépôt |
| `INSTAGRAM_CONFIG_ID` | vide | Identifiant de configuration Facebook Login for Business (applications récentes) |

Voir [Intégrations › Crosspost Instagram](Integrations-fr#crosspost-vers-instagram).

## Réglages avancés

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `STORAGE_DIR` | `/media` dans l'image | Emplacement des originaux et des médias convertis |
| `SQLITE_FOREIGN_KEYS` | `1` | Clés étrangères SQLite (désactivées automatiquement pendant les migrations) |
| `FEDERATION_ALLOW_PRIVATE_NETWORKS` | `0` | Fédération avec des adresses du réseau privé. **Tests locaux uniquement** |
| `APP_VERSION` | étiquette de l'image | Version affichée en bas du menu |
| `HTTP_PORT`, `HTTPS_PORT` | `80`, `443` | Ports publiés par le `compose.yaml` du dépôt |

## Notifications Web Push

Aucun réglage : les clés VAPID de l'instance sont créées au premier usage et chiffrées avec `APP_SECRET`. HTTPS est
indispensable. Sur iPhone et iPad, les notifications ne fonctionnent qu'une fois le site ajouté à l'écran d'accueil.

## Volumes

| Chemin | Contenu |
| --- | --- |
| `/data` | Base SQLite (et ses copies automatiques avant migration), clés de l'application |
| `/media` | Originaux (privés, avec métadonnées) et copies publiques (WebP, MP4, affiches) |

Les deux volumes sont à sauvegarder : voir [Mise à jour et sauvegarde](Mise-a-jour-et-sauvegarde).
