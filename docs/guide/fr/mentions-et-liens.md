# Mentions, hashtags et liens

Dans les légendes, les commentaires et la biographie :

| Saisie | Devient |
| --- | --- |
| `@nom` | une mention d'un compte de cette instance |
| `@nom@instance` | une mention d'un compte du fédivers (Mastodon, Pixelfed…) |
| `ig:@nom` | un lien vers un compte Instagram |
| `#mot` | un hashtag |
| `https://…`, `www.exemple.fr`, `exemple.fr/page` | un lien |

## Mentionner un compte

Les suggestions apparaissent dès « @ » : comptes suivis, comptes d'ici, puis du fédivers. Un second « @ » bascule vers
les comptes du fédivers seulement, et une adresse complète encore inconnue est cherchée sur son serveur. Échap ferme
les suggestions, Ctrl+Espace les rouvre. Les comptes mentionnés sont prévenus, ici comme dans le fédivers.

## Les liens

Les adresses web deviennent des liens, sur la page comme dans le fédivers, avec ou sans `https://`. Elles s'affichent
raccourcies : `https://www.exemple.fr/une/page/longue` devient `exemple.fr/une/page/lon…`. La ponctuation qui suit une
adresse n'en fait pas partie.

Un `#` ou un `@` à l'intérieur d'une adresse n'est ni un hashtag ni une mention.
