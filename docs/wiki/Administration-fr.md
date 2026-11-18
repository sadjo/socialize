# Administration

Le premier compte créé sur une instance est administrateur. Les administrateurs trouvent **Administration** dans le
menu (`/admin`).

## Comptes

- Liste des comptes de l'instance, administrateurs signalés.
- **Création d'un compte** en renseignant son e-mail, son nom d'utilisateur, son nom affiché et son mot de passe
  (sans longueur minimale pour un compte créé ainsi), avec ses droits.
- **Droits**, accordés ou retirés compte par compte :

| Droit | Permet |
| --- | --- |
| *Publication* | Publier, créer des stories, publier depuis Immich et Synology Photos |
| *Import Instagram* | Importer une archive Instagram (avec *Publication*) |

  Un nouveau compte n'a aucun droit tant qu'un administrateur ne l'accorde pas ; un administrateur a les deux.
- **Changer le mot de passe** d'un compte, **changer sa photo ou sa couverture**.
- **Supprimer** un compte, ou retirer l'accès d'un compte Mastodon connecté.
- **Modifier la date** d'une publication (depuis la publication).

## Invitations

Avec `REGISTRATION=invite` (par défaut), on rejoint l'instance sur invitation : *Administration › Invitations* crée un
lien à usage unique valable 7 jours, envoyé par e-mail (si `MAILER_DSN` est réglé) ou copié et transmis autrement.
Le lien fonctionne même quand les inscriptions sont fermées. Seule l'empreinte du jeton est gardée ; une invitation
en attente peut être révoquée.

## Bloc Instance

*Administration › Instance* modifie le bloc affiché à côté des fils : un titre et un texte en Markdown, avec barre
d'édition (gras, liens, listes, images envoyées sur l'instance). Idéal pour un mot d'accueil, des règles ou un contact.

## En console

Tout se fait aussi en ligne de commande, par exemple pour démarrer une instance fermée :

```bash
docker compose exec app php bin/console app:user:create alice@example.org alice --admin --name="Alice"
printf '%s' 'nouveau-mot-de-passe' | docker compose exec -T app php bin/console app:user:password alice --password-stdin
```

Voir [Commandes console](Commandes-console).

## Conseils de modération

- Gardez `REGISTRATION=invite` ou `closed` pour une instance privée, et `HOME_LOGIN_ONLY=1` pour tout cacher aux visiteurs.
- `MASTODON_LOGIN` peut se limiter à une liste de serveurs de confiance.
- Le canal de journal `federation` (`docker compose logs app`) trace chaque livraison et chaque activité reçue, avec
  le motif d'un refus.
