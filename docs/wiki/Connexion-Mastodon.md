# Connexion Mastodon

Une personne qui a déjà un compte Mastodon peut utiliser Socialize **sans créer de compte local** : elle se connecte
avec Mastodon (OAuth) et Socialize agit pour elle par l'API de son propre serveur.

## Activation

```bash
MASTODON_LOGIN=open                              # tout serveur Mastodon
MASTODON_LOGIN=mastodon.social,piaille.fr        # ou seulement ces serveurs
MASTODON_SIGNUP=mastodon.social                  # facultatif : créer un compte Mastodon depuis l'écran de connexion
```

- Si `mastodon.social` est permis, un bouton le propose d'un clic ; les autres serveurs se saisissent dans un champ.
- Socialize enregistre une application OAuth sur chaque serveur à la première connexion depuis celui-ci.
- Avec `MASTODON_SIGNUP`, l'écran de connexion propose de créer un compte sur ce serveur (qui doit aussi être permis
  par `MASTODON_LOGIN`). Le nouveau compte est privé (abonnés validés) par défaut. Limite : 3 créations par heure et
  par adresse IP.

## Ce que peut faire un compte Mastodon

- **Profil** : ses publications Mastodon ; le profil se modifie sur son serveur Mastodon.
- **Fil** : les publications des comptes qu'il suit et que l'instance connaît, comptes d'ici compris.
- **Stories** : celles des comptes d'ici qu'il suit, avec les mêmes règles de visibilité (abonnés, groupes) que pour
  tout abonné distant.
- **« J'aime », commentaires et abonnements** passent par l'API Mastodon de son serveur, qui les fédère.
- **Publication** : les photos et vidéos publiées depuis Socialize partent **sur son compte Mastodon**.
- **Import Instagram** : si un administrateur l'y autorise, il importe son archive Instagram sur son compte Mastodon
  (*Administration › Comptes › Comptes Mastodon*).

Socialize ne signe jamais rien au nom d'un compte Mastodon (il n'en a pas les clés) : chaque action est faite par
son serveur, avec le jeton accordé à la connexion, chiffré en base.

## Administration

Les comptes Mastodon connectés figurent dans *Administration › Comptes*. Un administrateur peut retirer l'accès d'un
compte (son jeton est supprimé) et remplacer sa photo ou sa couverture, sur Socialize seulement.
