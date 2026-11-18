# Développement

## Prérequis

Seulement **Docker** et **make** : PHP, Composer et tous les outils tournent dans le conteneur.

```bash
git clone https://github.com/sadjo/socialize.git
cd socialize
make up            # http://localhost:8080 — HTTP_PORT=8099 make up pour un autre port
make help          # toutes les commandes
```

| Commande | Rôle |
| --- | --- |
| `make up` / `make down` | Démarrer / arrêter l'environnement de développement |
| `make logs`, `make sh` | Suivre les journaux, ouvrir un shell dans le conteneur |
| `make test` | PHPUnit |
| `make quality` | PHP-CS-Fixer, Rector (simulation), PHPStan (niveau max), Deptrac — comme la CI |
| `make fix` | Appliquer Rector et le style |
| `make console c="…"` | Commande Symfony, par exemple `make console c="app:demo:seed"` |
| `make composer c="…"` | Composer, par exemple `make composer c="require symfony/uid"` |
| `make prod` | Construire l'image de production |
| `make immich-up` / `immich-seed` / `immich-down` | Immich de test en local |

Le code est monté dans le conteneur et FrankenPHP recharge les fichiers modifiés. Xdebug est installé mais désactivé :
`XDEBUG_MODE=debug make up`. Chaque environnement a sa base SQLite (`var/app_dev.db`, `var/app_test.db`) : les tests
ne vident jamais la base de développement. `make console c="app:demo:seed"` crée des comptes de démonstration
(`camille`, `hugo`, `ines.lmb`, mot de passe `demo-socialize`).

## Architecture

```
src/
  Core/          comptes, publications, stories, abonnements, notifications (socle commun)
  Media/         stockage, traitement des images et vidéos, photothèques
  Federation/    ActivityPub : acteurs, signatures, boîte de réception, livraisons
  Import/        import d'une archive Instagram
  Crosspost/     publication sur Instagram (API Graph de Meta)
  Integration/   Immich, Synology Photos, Openverse, Mastodon
  Web/           contrôleurs, formulaires, composants Twig, commandes console
  Admin/         espace d'administration
```

Chaque module suit `Domain` / `Application` / `Infrastructure`. **Deptrac** (`deptrac.yaml`) vérifie les frontières :
les modules métier ne dépendent que de `Core` et `Media` ; `Core` émet des événements écoutés par les autres modules
(fédération, crosspost, notifications).

- **Persistance** : Doctrine ORM, portable entre SQLite et MySQL/MariaDB, sans SQL propre à un moteur. Toute
  modification du schéma passe par une migration : `make console c="make:migration"`.
- **Interface** : Twig, Symfony UX (Turbo, Stimulus, Live Components) et AssetMapper, sans build Node.
- **Tâches de fond** : Symfony Messenger (transport Doctrine, files `federation`, `async`, `media`) et Symfony Scheduler.
- **Médias** : libvips (par FFI) pour les images, ffmpeg pour les vidéos, stockage Flysystem.

## Tests

Les tests PHPUnit étendent `WebTestCase` (HTTP) ou `KernelTestCase` (services). Les services externes sont remplacés
par des imitations : fausse instance du fédivers avec de vraies clés, faux Immich, Synology Photos, Openverse et API
Graph d'Instagram. La suite échoue à la moindre dépréciation ; la CI la lance sur SQLite et MariaDB.

## Immich de test

```bash
make immich-up     # Immich sur http://localhost:2283
make immich-seed   # admin@socialize.test / socialize-immich, ~20 photos générées, 2 vidéos, 2 albums
make immich-down   # arrêt (données gardées dans les volumes socialize-immich)
```

`make immich-seed` affiche l'adresse du serveur vue depuis l'application (`http://host.docker.internal:2283`) et une
nouvelle clé API à saisir dans *Réglages › Immich*.

## Versions

Versions `vMAJEUR.MINEUR.CORRECTIF`. Un tag poussé sur `main` lance le workflow *Release* : image multi-architecture
sur `ghcr.io/sadjo/socialize` (`vX.Y.Z` et `latest`) et release GitHub tirée du message du tag.

Voir aussi [CONTRIBUTING.md](https://github.com/sadjo/socialize/blob/main/CONTRIBUTING.md).
