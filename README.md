# Socialize

Réseau photo et vidéo auto-hébergé, compatible avec le fédivers (Mastodon, Pixelfed…) via ActivityPub.
Socialize permet d'importer une archive Instagram sans perte, de choisir ses médias dans [Immich](https://immich.app)
et de publier aussi sur Instagram et Facebook selon des règles réglables.

> **État : socle technique (jalon 1).** L'application démarre, se connecte à SQLite ou MariaDB et passe la CI.
> Les fonctionnalités arrivent jalon par jalon (voir la feuille de route du document de conception).

- Maquette de l'interface : [`maquette/socialize.html`](maquette/socialize.html)
- Stack : PHP 8.5, Symfony 8.1, FrankenPHP (mode worker), Doctrine ORM, Symfony Messenger, Twig + Symfony UX.

## Lancer une instance

Une seule image, un seul conteneur. Les données vivent dans deux volumes : `/data` (base SQLite, clés, certificats)
et `/media` (photos et vidéos).

### Avec `docker run` (SQLite)

```bash
docker build -t socialize --target frankenphp_prod .

docker run -d --name socialize \
  -p 80:80 -p 443:443 -p 443:443/udp \
  -e SERVER_NAME=photos.example.org \
  -e APP_SECRET="$(openssl rand -hex 32)" \
  -v socialize_data:/data -v socialize_media:/media \
  socialize
```

Caddy obtient automatiquement un certificat HTTPS pour `SERVER_NAME`.

### Avec Docker Compose

```bash
cp docker.env.example docker.env   # renseigner APP_SECRET et SERVER_NAME
docker compose up -d               # SQLite
```

Avec MariaDB lancée à côté :

```bash
export MARIADB_PASSWORD="$(openssl rand -hex 16)"   # à conserver
docker compose -f compose.yaml -f compose.mariadb.yaml up -d
```

Ou avec un serveur MySQL / MariaDB existant : définissez `DATABASE_URL` dans `docker.env`.

### Derrière un reverse proxy

Si un reverse proxy (NAS, Traefik…) gère déjà le HTTPS, utilisez `SERVER_NAME=:80`, publiez seulement le port 80
et renseignez `TRUSTED_PROXIES`.

### Variables d'environnement

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `SERVER_NAME` | `localhost` | Domaine public ; `:80` pour du HTTP seul derrière un proxy |
| `APP_SECRET` | aucune (**obligatoire**) | Secret de l'application : `openssl rand -hex 32` |
| `DEFAULT_URI` | `http://localhost` | URL publique utilisée hors requête HTTP |
| `DATABASE_URL` | `sqlite:////data/socialize.db` | Ou `mysql://user:pass@hote:3306/socialize?serverVersion=11.4.0-MariaDB&charset=utf8mb4` |
| `WORKERS` | `1` | Consommateurs Messenger lancés dans le conteneur, `0` pour aucun |
| `TRUSTED_PROXIES` | vide | Adresses des reverse proxies de confiance |

Au démarrage, le conteneur applique les migrations (avec une copie de sécurité préalable de la base SQLite),
prépare la file Messenger, puis lance FrankenPHP et le consommateur Messenger. La sonde `/healthz` renvoie l'état
de l'instance au format JSON.

### Mise à jour

```bash
docker compose pull   # ou docker compose build
docker compose up -d
```

## Développer

Seuls Docker et `make` sont nécessaires sur le poste : PHP et Composer tournent dans le conteneur.

```bash
make up          # http://localhost:8080 (HTTP_PORT=8099 make up pour un autre port)
make test        # PHPUnit
make quality     # PHP-CS-Fixer, Rector, PHPStan (niveau max), Deptrac
make fix         # applique Rector et le style
make console c="debug:router"
make help        # toutes les commandes
```

Le code est monté dans le conteneur et FrankenPHP recharge les fichiers modifiés. Xdebug est installé mais désactivé :
`XDEBUG_MODE=debug make up` pour l'activer.

### Organisation du code

Le code est découpé en modules sous `src/` (`Core`, `Media`, `Federation`, `Import/Instagram`, `Crosspost/Meta`,
`Integration/Immich`, `Api`, `Mcp`, `Web`, `Admin`). Chaque module suit la structure `Domain` / `Application` /
`Infrastructure`. Deptrac vérifie que les modules métier ne dépendent que du socle (`Core` et `Media`) : voir `deptrac.yaml`.

### Qualité et montées de version

- PHPStan au niveau maximal, PHP-CS-Fixer (`@Symfony`, `@Symfony:risky`), Deptrac.
- Les tests échouent à la moindre dépréciation (`failOnDeprecation`).
- Rector applique les migrations de PHP et de Symfony : `make fix` après une montée de version dans `composer.json`.
- La CI (`.github/workflows/ci.yml`) exécute la qualité et les tests sur SQLite et MariaDB à chaque push et pull request.
