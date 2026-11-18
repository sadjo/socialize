# Guide d'utilisation

## Votre compte

- **Connexion** avec l'adresse e-mail ou le nom d'utilisateur (`camille` ou `@camille`). Une personne qui n'a qu'un
  compte Mastodon utilise *Se connecter avec Mastodon* si l'instance le permet (voir [Connexion Mastodon](Connexion-Mastodon)).
- **Profil** (*Réglages*) : nom affiché, biographie, photo de profil et image de couverture (bandeau 4:1), recadrées
  dans le navigateur (glisser, zoomer) et repositionnables plus tard.
- **Confidentialité** : *Réserver mes listes à mes abonnés* cache vos listes d'abonnés et d'abonnements aux autres.
- **Apparence** (*Réglages › Apparence*) : thème clair, sombre, violet nuit ou automatique, avec aperçu immédiat, et
  **filtre des médias** (désactivé, léger, moyen, marqué) qui adoucit les couleurs des photos et vidéos dans les fils
  et les grilles — la page d'une publication, la visionneuse et les stories gardent les couleurs d'origine.
- **Application sur l'écran d'accueil** : sur iPhone/iPad, *Partager › Sur l'écran d'accueil* ; sur Android,
  *Installer l'application*. Socialize s'ouvre alors en plein écran et peut envoyer des notifications.

Votre adresse dans le fédivers est `@nom@votre-instance` : donnez-la à vos proches sur Mastodon pour qu'ils vous suivent.

## Publier

Le bouton **Créer** propose *Publication* ou *Story*. Seuls les comptes qui ont le droit **Publication** peuvent
publier (toujours acquis aux administrateurs).

### Publications

- Jusqu'à **20 photos et vidéos**, depuis l'appareil ou une photothèque connectée ([Immich, Synology Photos](Integrations-fr)).
  Les sélections s'ajoutent ; ordre par glisser-déposer ou ◀ ▶ ; loupe pour un aperçu en grand ; retrait d'un média
  depuis l'aperçu.
- **Texte alternatif** pour chaque média : décrivez vos images pour les personnes qui utilisent un lecteur d'écran.
- **Légende** avec `#hashtags` et mentions (voir plus bas), **lieu** et **avertissement de contenu** facultatif qui
  floute les médias jusqu'à ce qu'on choisisse de les voir.
- **Musique** (*Ajouter une musique*) : recherche de morceaux Creative Commons dans Openverse, pré-écoute, choix du
  début de la boucle de 30 s. Les crédits s'affichent automatiquement ; vos favoris sont proposés en premier.
- **Visibilité** :

| Visibilité | Qui la voit | Envoyée dans le fédivers |
| --- | --- | --- |
| *Public* | Tout le monde, dans les fils publics | Oui |
| *Non répertorié* | Toute personne qui a le lien, absente des fils | Non |
| *Abonnés* | Vos abonnés acceptés | Oui |
| *Groupes* | Les abonnés membres des groupes choisis | Non |
| *Privé* | Vous seul | Non |

Les photos sont converties en WebP et les vidéos en MP4 en tâche de fond ; une publication avec vidéo reste *en
attente* pendant la conversion, avec sa progression sur votre profil.

Une fois publiée, une publication se **modifie** (légende, lieu, visibilité, avertissement, médias), s'**archive**
(elle quitte le profil et les fils mais reste visible pour vous dans *Archives*) ou se **supprime**.

### Stories

- Une photo ou une vidéo par story, jusqu'à 10 à la fois, avec texte superposé et musique facultatifs.
- Visibles **24 heures** par tout le monde, par vos abonnés ou par des groupes.
- Une story expirée rejoint vos archives ; depuis *Mes stories*, mettez-la **à la une** sur votre profil.
- Vous voyez qui a vu vos stories.

## Groupes d'abonnés

*Réglages › Groupes* : créez des groupes (famille, amis proches…) et choisissez leurs membres parmi vos abonnés. À la
publication, choisissez la visibilité *Groupes* et un ou plusieurs groupes. Les membres voient un badge *Groupe* ;
vous voyez les noms des groupes. Supprimer un groupe rend privées les publications qui ne visaient que lui.

## Fil, « J'aime » et commentaires

- **Accueil** (*Publications*) : vos publications et celles des comptes suivis. La barre des stories montre d'abord
  les vôtres, puis les stories non vues des comptes suivis.
- **J'aime** avec le cœur ou un double tap ; le compteur montre qui a aimé.
- **Commentaires** directement dans le fil ; l'auteur du commentaire ou de la publication peut le supprimer.
- **Menu ⋯** : ouvrir, partager (e-mail, lien, Facebook, Instagram, partage du système), voir sur l'instance
  d'origine, modifier, archiver, supprimer.
- Les vidéos se lisent sans le son quand elles sont visibles ; le bouton son vaut pour toutes. Un clic sur une photo
  ou une vidéo ouvre la visionneuse plein écran (flèches, clavier, balayage).

## Mentions

Dans les légendes et les commentaires :

| Syntaxe | Mentionne |
| --- | --- |
| `@nom` | un compte de cette instance |
| `@nom@instance` | un compte du fédivers (Mastodon, Pixelfed…) |
| `ig:@nom` | un compte Instagram |

Les suggestions apparaissent dès « @ » : comptes suivis, comptes d'ici, puis du fédivers. Un second « @ » bascule
vers les comptes du fédivers seulement, et une adresse complète encore inconnue est cherchée sur son serveur. Échap
ferme les suggestions, Ctrl+Espace les rouvre. Les comptes mentionnés sont prévenus.

## Suivre

La *Recherche* trouve les comptes par nom ou par adresse du fédivers (`@nom@instance`). Suivre un compte distant
importe ses publications photo et vidéo récentes. Un compte qui valide ses abonnés affiche *Demande envoyée*
jusqu'à son accord. Sur votre profil, vous pouvez retirer un abonné ou ne plus suivre un compte.

## Notifications

*Notifications* liste les « J'aime », commentaires et mentions reçus d'ici et du fédivers, à marquer lus ou non lus,
ou à supprimer. Les administrateurs sont aussi prévenus des nouveaux comptes.

*Notifications › Sur cet appareil* active les **notifications** sur l'appareil : nouvelles publications et stories
des comptes suivis, « J'aime » et commentaires, chaque type pouvant être coupé.

## Importer son archive Instagram

Avec le droit **Import Instagram**, voir [Intégrations › Import Instagram](Integrations-fr#import-instagram).
