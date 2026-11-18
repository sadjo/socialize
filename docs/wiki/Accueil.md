# Documentation de Socialize

**Socialize** est un réseau social photo et vidéo auto-hébergé, compatible avec le fédivers (Mastodon, Pixelfed…)
grâce à ActivityPub. Ce wiki explique comment l'installer, le configurer, l'exploiter et l'utiliser.

> 🇬🇧 The documentation is also available [in English](Home).

## Démarrer

| Page | Contenu |
| --- | --- |
| [Installation](Installation-fr) | Installation en une ligne, Docker, Docker Compose, MariaDB, reverse proxy |
| [Configuration](Configuration-fr) | Toutes les variables d'environnement, e-mail, inscriptions, workers |
| [Mise à jour et sauvegarde](Mise-a-jour-et-sauvegarde) | Mettre à jour, sauvegarder et restaurer une instance |

## Utiliser Socialize

| Page | Contenu |
| --- | --- |
| [Guide d'utilisation](Guide-d-utilisation) | Publications, stories, groupes d'abonnés, mentions, musique, notifications, thèmes |
| [Administration](Administration-fr) | Comptes, invitations, droits, bloc Instance |
| [Fédération](Federation-fr) | Les échanges avec Mastodon, Pixelfed et le fédivers |
| [Connexion Mastodon](Connexion-Mastodon) | Utiliser Socialize avec son compte Mastodon |
| [Intégrations](Integrations-fr) | Import et crosspost Instagram, Immich, Synology Photos, Openverse |

## Référence

| Page | Contenu |
| --- | --- |
| [Commandes console](Commandes-console) | Toutes les commandes `app:*` |
| [Développement](Developpement) | Environnement local, tests, qualité, architecture |
| [FAQ](FAQ-fr) | Dépannage et questions fréquentes |

## En bref

- Une seule image Docker (FrankenPHP : Caddy + PHP 8.5) avec HTTPS automatique et deux volumes : `/data` et `/media`.
- SQLite par défaut, MariaDB ou MySQL pour les instances plus importantes.
- Photos converties en WebP (libvips), vidéos en MP4 H.264 (ffmpeg), en tâche de fond.
- Les originaux gardent leurs métadonnées en privé ; les copies publiques n'ont jamais d'EXIF ni de GPS.
- Publié sous licence [AGPL-3.0](https://github.com/sadjo/socialize/blob/main/LICENSE).
