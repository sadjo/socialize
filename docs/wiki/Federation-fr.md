# Fédération

Socialize implémente [ActivityPub](https://www.w3.org/TR/activitypub/) et échange avec Mastodon, Pixelfed et les
autres logiciels du fédivers.

## Ce qui est pris en charge

- **Découverte** : WebFinger (`@nom@domaine`), NodeInfo 2.1, host-meta ; un acteur `Person` par compte et un acteur
  d'instance.
- **Sécurité** : signatures HTTP (draft-cavage, rsa-sha256) en envoi et en réception, `Digest` et `Date` vérifiés,
  auteur comparé au signataire, clés relues quand elles changent. Chaque compte a sa paire de clés RSA ; les clés
  privées sont chiffrées avec `APP_SECRET`. Les requêtes sortantes n'atteignent jamais le réseau privé.
- **Objets** : une publication est un objet `Note` avec ses photos et vidéos (texte alternatif, blurhash, vignettes
  des vidéos), hashtags, mentions, avertissement de contenu et crédit musical ; les commentaires sont des réponses.
- **Activités** : Follow / Accept / Reject / Undo, Like, Create, Update, Delete, envoyées en tâche de fond aux boîtes
  partagées, avec nouvel essai en cas d'erreur.
- **Historique** : quand un compte distant accepte un abonnement, ses publications photo et vidéo des six derniers
  mois (50 au plus) sont importées.

## Visibilité et fédivers

Seules les publications **publiques** et **réservées aux abonnés** partent dans le fédivers. Les non répertoriées et
celles des groupes restent sur l'instance.

Mastodon ne change jamais la visibilité ni les destinataires d'une publication déjà reçue. Quand l'auteur les change,
Socialize retire la publication du fédivers (`Delete`) puis la renvoie sous un nouvel identifiant (`/p/…/v2`,
`/v3`…). Sur Mastodon, la nouvelle version repart sans les « J'aime » ni les partages de l'ancienne ; ceux déjà reçus
restent sur Socialize. Modifier une publication sans changer son public envoie un simple `Update`.

## Le nom de domaine

`DEFAULT_URI` est la base de toutes les adresses ActivityPub. Choisissez-le avant de fédérer et ne le changez plus :
les autres serveurs reconnaissent vos comptes et vos publications à ces adresses.

## Commandes de maintenance

```bash
# Retirer du fédivers les anciennes copies d'une publication, puis la renvoyer si elle est visible
bin/console app:federation:resync https://photos.example.org/p/1Ch1rCiDZ7zSfRdPMRVyPC
# …publication supprimée : indiquer le compte qui l'avait publiée
bin/console app:federation:resync https://photos.example.org/p/1Ch1rCiDZ7zSfRdPMRVyPC/v4 --compte=alice

# Renvoyer les publications d'un compte de la plus ancienne à la plus récente (ordre chronologique sur Mastodon)
bin/console app:federation:reorder alice --essai               # simulation
bin/console app:federation:reorder alice --depuis=2025-09-05   # depuis une date, pause de 5 s entre deux

# Retirer du fédivers les publications non répertoriées et de groupe envoyées par d'anciennes versions
bin/console app:federation:retract-local-only --simulation
```

À lancer avec `docker compose exec app php bin/console …`. Voir [Commandes console](Commandes-console).

## Dépannage

- **Une recherche distante ne donne rien** : l'instance doit être joignable en HTTPS depuis Internet et
  `DEFAULT_URI` doit être son adresse publique. Les serveurs en *authorized fetch* (comme mastodon.social) exigent
  des lectures signées, que Socialize fait avec son acteur d'instance.
- **Une publication n'apparaît pas sur Mastodon** : consultez le canal `federation` du journal
  (`docker compose logs app | grep federation`) pour le code HTTP renvoyé par le serveur distant.
- **Tests en local** : `FEDERATION_ALLOW_PRIVATE_NETWORKS=1` permet la fédération avec des adresses privées. Jamais
  en production.
