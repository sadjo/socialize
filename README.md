# Socialize

Réseau photo et vidéo auto-hébergé, compatible avec le fédivers (Mastodon, Pixelfed…) via ActivityPub.
Socialize permet d'importer une archive Instagram sans perte, de choisir ses médias dans [Immich](https://immich.app)
ou Synology Photos, et de publier aussi sur Instagram et Facebook selon des règles réglables.

> **État : jalon 8.** Comptes, publications photo et vidéo, fils, profils, abonnements, likes, commentaires,
> stories, archives, fédération ActivityPub (Mastodon, Pixelfed), import d'une archive Instagram et publication
> depuis Immich ou Synology Photos : photos et vidéos choisies dans la photothèque, originaux et métadonnées copiés.
> Musique libre sur les publications et les stories, choisie dans [Openverse](https://openverse.org)
> (licences Creative Commons, crédit affiché), copiée sur l'instance et jouée quand la publication est à l'écran.
> Groupes d'abonnés (famille, amis…) : publications et stories réservées à un ou plusieurs groupes, y compris pour
> les abonnés du fédivers, et groupe par défaut à l'import Instagram. Invitations et création de comptes par l'administrateur,
> qui choisit quels comptes peuvent publier et lesquels peuvent importer leur archive Instagram.
> Crosspost vers Instagram : publications et stories envoyées aussi sur un compte Instagram professionnel, selon
> des règles par visibilité ; bouton « Partager vers Instagram » pour tout compte, personnel compris.
> Notifications des « J'aime » et commentaires reçus (d'ici ou du fédivers), lues ou non lues, supprimables.
> Jalons suivants : crosspost Facebook (jalon 9), API REST et serveur MCP (jalon 10) ; à prévoir : notifications
> réglables par compte.

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

Si un reverse proxy (Traefik, Nginx, Caddy…) gère déjà le HTTPS, utilisez `SERVER_NAME=:80`, publiez seulement le port 80
et renseignez `TRUSTED_PROXIES`.

### Fédération (ActivityPub)

`DEFAULT_URI` sert de base à toutes les adresses ActivityPub (acteurs, publications) : choisissez-la avant
d'ouvrir l'instance au fédivers et ne la changez plus. Les comptes se trouvent par WebFinger
(`@nom@domaine`), chaque compte local a sa paire de clés RSA (clé privée chiffrée avec `APP_SECRET`), et les
activités reçues sont vérifiées par signature HTTP. Les requêtes vers les autres instances n'atteignent
jamais le réseau privé.

Mastodon ne change jamais la visibilité ni les destinataires d'une publication déjà reçue. Quand l'auteur les
change, la publication est donc retirée du fédivers (Delete) puis renvoyée sous un nouvel identifiant (`/p/…/v2`,
`/v3`…). Sur Mastodon, la nouvelle version repart sans les « J'aime » ni les partages de l'ancienne ; ceux déjà
reçus restent sur Socialize.
Si des copies d'une publication restent sur d'autres instances (après un changement ou une suppression faits
avant la v0.7.10) :

```bash
bin/console app:federation:resync https://socialize.example/p/1Ch1rCiDZ7zSfRdPMRVyPC            # publication existante
bin/console app:federation:resync https://socialize.example/p/1Ch1rCiDZ7zSfRdPMRVyPC/v4 --compte=alice   # supprimée
```

### Variables d'environnement

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `SERVER_NAME` | `localhost` | Domaine public ; `:80` pour du HTTP seul derrière un proxy |
| `APP_SECRET` | aucune (**obligatoire**) | Secret de l'application : `openssl rand -hex 32` |
| `DEFAULT_URI` | `http://localhost` | URL publique utilisée hors requête HTTP |
| `DATABASE_URL` | `sqlite:////data/socialize.db` | Ou `mysql://user:pass@hote:3306/socialize?serverVersion=11.4.0-MariaDB&charset=utf8mb4` |
| `WORKERS` | `1` | Consommateurs Messenger lancés dans le conteneur, `0` pour aucun |
| `TRUSTED_PROXIES` | vide | Adresses des reverse proxies de confiance |
| `REGISTRATION` | `invite` | Inscriptions : `open`, `invite` (seul le premier compte, administrateur, peut s'inscrire) ou `closed` (aucune ; administrateur créé avec `app:user:create`) |

Au démarrage, le conteneur applique les migrations (avec une copie de sécurité préalable de la base SQLite),
prépare la file Messenger, puis lance FrankenPHP et le consommateur Messenger. La sonde `/healthz` renvoie l'état
de l'instance au format JSON.

Ouvrez ensuite l'instance et créez le premier compte depuis « Créer un compte » : il devient administrateur.

Seuls les comptes autorisés peuvent publier (publications, stories, Immich, Synology Photos) et, avec un second droit, importer leur
archive Instagram. Un administrateur a toujours ces droits ; pour les autres, ils s'accordent ou se retirent dans
Administration › Comptes (ou à la création : `app:user:create --publisher --instagram-import`). Un nouveau compte n'a
aucun des deux tant qu'il n'y est pas autorisé.

Les photos sont converties en WebP (grande taille et miniature) par libvips, les vidéos en MP4 H.264 par ffmpeg,
en tâche de fond. Les copies publiques sont servies sous `/m/` et ne contiennent plus de métadonnées (dont la
position GPS) ; les originaux, avec leur EXIF, restent dans `/media/originals` et ne sont jamais exposés.

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
make console c="app:demo:seed"   # comptes et publications d'exemple (mot de passe : demo-socialize)
make console c="app:import:instagram camille /media/instagram.zip"   # importe une archive déjà sur le serveur
make help        # toutes les commandes
```

Le code est monté dans le conteneur et FrankenPHP recharge les fichiers modifiés. Xdebug est installé mais désactivé :
`XDEBUG_MODE=debug make up` pour l'activer.

### Crosspost Instagram

Réglages › Instagram : un compte autorisé à publier y connecte son compte Instagram **professionnel** (Créateur ou
Entreprise, exigé par l'API d'Instagram). Les règles choisissent les visibilités pour lesquelles « Publier aussi sur
Instagram » est coché d'avance à la création (et si elles valent pour les stories) ; la case reste modifiable à chaque
publication. L'envoi se fait en tâche de fond quand les médias sont prêts : photos converties en JPEG et mises aux
proportions acceptées par des bandes (sans recadrage), vidéo seule en Reel (3 s à 15 min), carrousel jusqu'à 20
médias, story. L'état apparaît sur la page de la publication, avec « Réessayer » en cas d'échec. Instagram ne permet
**ni de modifier ni de supprimer** une publication par son API : ces copies se gèrent dans l'application Instagram.
Le jeton (60 jours) est renouvelé automatiquement.

Application Meta : créer une application sur [developers.facebook.com](https://developers.facebook.com) (type
Business, cas d'usage « API Instagram avec connexion Instagram ») et y déclarer l'adresse de retour
`https://<instance>/reglages/instagram/retour`. Chaque compte qui peut publier renseigne la sienne dans Réglages ›
Instagram › Application Meta (identifiant et clé secrète, chiffrée en base, jamais réaffichée). L'instance peut aussi
en fournir une par défaut, pour les comptes qui n'en ont pas : `INSTAGRAM_APP_ID` (variable) et `INSTAGRAM_APP_SECRET`
(secret), facultatifs. Sans validation de l'application par Meta (accès standard), seuls les comptes Instagram ajoutés
comme **testeurs** de l'application peuvent se connecter.

Sans application Meta, ou pour un compte Instagram personnel : « Partager vers Instagram » (menu « … » d'une
publication, page Mes stories) ouvre le menu de partage du téléphone avec les photos en JPEG et les vidéos, et copie
la légende ; sur un ordinateur, les fichiers se téléchargent.

### Synology Photos

Réglages › Synology Photos : le compte saisit l'adresse de son NAS (DSM 7), son identifiant et son mot de passe DSM,
et au besoin son code à usage unique. Ils ouvrent une session sur le NAS ; seule cette session est gardée, chiffrée
(jamais le mot de passe). À la création d'une publication ou d'une story, l'onglet « Synology Photos » montre l'espace
personnel, l'espace partagé et les albums. La session reste valable tant qu'elle sert (DSM › Panneau de configuration ›
Sécurité › Minuteur de déconnexion, jusqu'à 45 jours d'inactivité) ; si le NAS ne la reconnaît plus, l'instance
propose de se reconnecter. Le NAS doit être joignable depuis le serveur (pas d'adresse QuickConnect).

L'API de Synology Photos n'est pas documentée par Synology : `SynologyPhotosClient` s'appuie sur des appels vérifiés
sur un NAS réel (DSM 7), qu'une mise à jour de DSM peut changer.

### Immich de test

Pour développer l'intégration Immich, une instance de test tourne à côté de l'application (projet Compose séparé,
sans reconnaissance des personnes) :

```bash
make immich-up     # Immich v3.4.1 sur http://localhost:2283
make immich-seed   # compte admin@socialize.test / socialize-immich, ~20 photos et 2 vidéos générées, 2 albums
make immich-down   # arrêt (les données sont gardées dans les volumes du projet socialize-immich)
```

`make immich-seed` affiche l'adresse du serveur vue depuis l'application (`http://host.docker.internal:2283`)
et une nouvelle clé API, à saisir dans Réglages › Immich. La clé a seulement les droits dont Socialize a besoin :
`user.read`, `asset.read`, `asset.view`, `asset.download`, `asset.statistics`, `album.read`, `album.create`,
`albumAsset.create`. Le script est relançable sans créer de doublon.

### Organisation du code

Le code est découpé en modules sous `src/` (`Core`, `Media`, `Federation`, `Import/Instagram`, `Crosspost/Meta`,
`Integration/Immich`, `Integration/Openverse`, `Api`, `Mcp`, `Web`, `Admin`). Chaque module suit la structure `Domain` / `Application` /
`Infrastructure`. Deptrac vérifie que les modules métier ne dépendent que du socle (`Core` et `Media`) : voir `deptrac.yaml`.

### Qualité et montées de version

- PHPStan au niveau maximal, PHP-CS-Fixer (`@Symfony`, `@Symfony:risky`), Deptrac.
- Les tests échouent à la moindre dépréciation (`failOnDeprecation`).
- Rector applique les migrations de PHP et de Symfony : `make fix` après une montée de version dans `composer.json`.
- La CI (`.github/workflows/ci.yml`) exécute la qualité et les tests sur SQLite et MariaDB à chaque push et pull request.
