# Commandes console

Dans le conteneur :

```bash
docker compose exec app php bin/console <commande>
# avec docker run :
docker exec -it socialize php bin/console <commande>
```

## Comptes

| Commande | Rôle |
| --- | --- |
| `app:user:create <email> <nom>` | Crée un compte. Options : `--admin`, `--publisher`, `--instagram-import` (avec `--publisher`), `--name="Nom affiché"`, `--password-stdin` |
| `app:user:password <email-ou-nom>` | Change un mot de passe (saisi deux fois, ou `--password-stdin`) |

```bash
printf '%s' 'S3cret!' | docker compose exec -T app php bin/console app:user:create bob@example.org bob --publisher --password-stdin
```

## Instagram

| Commande | Rôle |
| --- | --- |
| `app:import:instagram <compte> <zip>...` | Importe une archive déjà sur le serveur. Options : `--visibility=public_quiet\|public\|followers\|private` (`public_quiet` par défaut), `--today` (date du jour), `--without-stories`, `--group=<nom>` (groupe d'abonnés des publications « abonnés ») |
| `app:import:announce <compte>` | Annonce aux abonnés du fédivers les publications importées discrètement, de la plus ancienne à la plus récente. Options : `--dry-run`, `--pause=1` (secondes) |

## Fédération

| Commande | Rôle |
| --- | --- |
| `app:federation:resync <adresse-ou-identifiant>` | Retire du fédivers les anciennes copies d'une publication, puis la renvoie si elle est visible. Publication supprimée : `--compte=<compte>` et au besoin `--revisions=<n>` |
| `app:federation:reorder <compte>` | Renvoie les publications d'un compte dans l'ordre chronologique. Options : `--depuis=AAAA-MM-JJ`, `--pause=5`, `--essai` (simulation) |
| `app:federation:refresh <compte>` | Met à jour dans le fédivers le contenu des publications d'un compte (Update, sans perdre leurs réactions ; Mastodon les marque « modifiées »), par exemple pour que les adresses web des anciennes légendes deviennent des liens. Par défaut, seulement les légendes avec une adresse web. Options : `--publications=<id>,<id>`, `--toutes`, `--depuis=AAAA-MM-JJ`, `--pause=1`, `--essai` (simulation) |
| `app:federation:retract-local-only` | Retire du fédivers les publications non répertoriées et de groupe envoyées par d'anciennes versions. `--simulation` pour les lister |

## Médias

| Commande | Rôle |
| --- | --- |
| `app:media:complete-sizes` | Fabrique les tailles manquantes (images 640/960 px, vignettes 240/360/720 px, affiches de vidéos) des médias publiés par d'anciennes versions. Relançable sans effet |

## Développement

| Commande | Rôle |
| --- | --- |
| `app:demo:seed` | Comptes, publications et stories de démonstration (refusé en production). Mot de passe : `demo-socialize` |

## Commandes Symfony utiles

| Commande | Rôle |
| --- | --- |
| `doctrine:migrations:status` | État des migrations (appliquées automatiquement au démarrage) |
| `messenger:stats` | Messages en attente par file |
| `messenger:failed:show` / `messenger:failed:retry` | Voir et relancer les tâches de fond en échec |
| `debug:router` | Routes de l'application |
