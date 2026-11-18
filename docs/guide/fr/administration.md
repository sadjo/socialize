# Administration

Le premier compte créé sur une instance est administrateur. Les administrateurs trouvent **Administration** dans le
menu.

## Comptes

*Administration › Comptes* liste les comptes de l'instance, administrateurs signalés, et les comptes Mastodon
connectés.

- **Créer un compte** : e-mail, nom d'utilisateur, nom affiché, mot de passe (sans longueur minimale ici) et droits.
- **Droits**, accordés ou retirés compte par compte :

| Droit | Permet |
| --- | --- |
| *Publication* | Publier, créer des stories, publier depuis Immich et Synology Photos |
| *Import Instagram* | Importer une archive Instagram (avec *Publication*) |

Un nouveau compte n'a aucun droit tant qu'un administrateur ne l'accorde pas ; un administrateur a les deux. Vous êtes
prévenu de chaque nouveau compte dans les notifications.

- **Mot de passe** : en choisir un nouveau pour un compte qui l'a oublié, puis le lui transmettre ; ses appareils sont
  déconnectés.
- **Photo et couverture** d'un compte, à remplacer si besoin.
- **Supprimer** un compte, ou retirer l'accès d'un compte Mastodon connecté.
- **Date d'une publication** : modifiable depuis la publication elle-même.

## Invitations

*Administration › Invitations* crée un lien d'inscription à usage unique, valable 7 jours, envoyé par e-mail ou copié
pour le transmettre autrement. Il fonctionne même quand les inscriptions sont fermées ; une invitation en attente peut
être révoquée.

## Bloc Instance

*Administration › Instance* modifie le bloc affiché à côté des fils : un titre et un texte en Markdown, avec une barre
d'édition (gras, liens, listes, images). Il convient à un mot d'accueil, aux règles de l'instance ou à un contact.

## Réglages du serveur

Ces choix se font dans la configuration de l'instance (fichier d'environnement), puis au redémarrage :

- `REGISTRATION` : `invite` (sur invitation, par défaut), `open` ou `closed` ;
- `HOME_LOGIN_ONLY=1` : tout cacher aux visiteurs non connectés ;
- `MASTODON_LOGIN` : connexion avec Mastodon, ouverte à tout serveur ou limitée à une liste.

## En console

Tout se fait aussi en ligne de commande, par exemple :

```bash
docker compose exec app php bin/console app:user:create alice@example.org alice --admin --name="Alice"
printf '%s' 'nouveau-mot-de-passe' | docker compose exec -T app php bin/console app:user:password alice --password-stdin
docker compose exec app php bin/console app:federation:refresh alice --essai
```

La liste complète figure dans la documentation de l'instance (*Commandes console*).
