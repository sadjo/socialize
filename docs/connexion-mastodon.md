# Note de conception : se connecter avec un compte Mastodon

> Jalon 9 (voir le README).

## Contexte

Aujourd'hui, une personne de Mastodon peut déjà suivre un compte Socialize, aimer et commenter ses
publications, mais depuis son application Mastodon. L'idée : qu'elle puisse **se connecter à Socialize avec
son compte Mastodon** et s'en servir comme d'une application Mastodon, avec l'interface photo et vidéo de
Socialize :

- son profil Socialize montre ses publications Mastodon ;
- elle aime, commente et suit les comptes Socialize ;
- elle publie des photos et des vidéos sur son compte Mastodon.

Choix arrêtés :

- **Identité : son compte Mastodon.** Aucun compte `@x@socialize.example` n'est créé. Socialize agit pour elle
  par l'API de son serveur Mastodon, et ses actions arrivent ensuite par la fédération, comme aujourd'hui.
- **Stories : pas de création** pour ces comptes (Mastodon n'en a pas). En revanche, ils **voient les stories des
  comptes Socialize et interagissent avec**, selon la visibilité de chaque story.
- **Import Instagram** : un administrateur peut l'autoriser pour un compte Mastodon. Les publications importées
  sont alors **publiées seulement sur son compte Mastodon** (aucune copie locale).

## Principe

Le compte Mastodon est déjà, pour Socialize, un **compte distant** (`Account::remote`, connu par
`RemoteAccounts`). La connexion Mastodon ne fait que permettre à une personne de « piloter » ce compte distant :

1. **Lecture** : son profil et ses publications viennent de la fédération (déjà en place : `RemoteAccounts`,
   `BackfillAccount`, `InboxProcessor`).
2. **Écriture** : chaque action (J'aime, commentaire, abonnement, publication) est un appel à l'API de son serveur
   Mastodon avec son jeton. Son serveur envoie ensuite l'activité ActivityPub à Socialize, qui la traite comme
   toute activité distante. Socialize ne signe jamais rien au nom de ce compte (il n'en a pas la clé).
3. Pour un affichage immédiat, l'action est aussi enregistrée en local avec l'identifiant renvoyé par Mastodon
   (`uri` du statut). Quand l'activité fédérée arrive, `InboxProcessor` la reconnaît à cet identifiant, sans doublon.

## Module et fichiers

Nouveau module `src/Integration/Mastodon/{Domain,Application,Infrastructure}`, déclaré dans `deptrac.yaml` comme
les intégrations Immich et Synology Photos.

- **Domain**
  - `MastodonApp` : application OAuth enregistrée une fois par serveur Mastodon (domaine, `client_id`,
    `client_secret` chiffré). Table `mastodon_apps`.
  - `MastodonUser` : la personne connectée avec Mastodon. Une ligne par compte distant : compte, jeton chiffré,
    droits OAuth accordés, rôles Socialize donnés par un administrateur (dont `ROLE_INSTAGRAM_IMPORTER`),
    dates de création et de dernière connexion. Table `mastodon_users`.
- **Infrastructure**
  - `MastodonClient` : API v1 et v2 de Mastodon (`/api/v1/apps`, `/oauth/token`, `/oauth/revoke`,
    `/api/v1/accounts/verify_credentials`, `/api/v2/search?resolve=true`, `/statuses/:id/favourite`,
    `/statuses` (POST, PUT, DELETE), `/api/v2/media`, `/accounts/:id/follow`, `/api/v2/instance`).
    Client HTTP `NoPrivateNetworkHttpClient` (serveur forcément public), remplacé dans les tests par un
    `FakeMastodon` sur le modèle de `tests/Integration/FakeImmich.php`.
  - `MastodonVault` : chiffrement des jetons, sur le modèle de `ImmichVault` / `InstagramVault`.
  - Repositories Doctrine, migration (compatible SQLite et MariaDB).
- **Application**
  - `MastodonLogin` : enregistrement de l'application sur le serveur (une fois), adresse d'autorisation
    (OAuth 2 avec PKCE et `state`), échange du code, identification du compte (`verify_credentials`, puis
    `RemoteAccounts::byHandle`).
  - `MastodonActions` : J'aime, commentaire, abonnement, publication, modification et suppression, par l'API.
  - `PublishToMastodon` et son handler Messenger : envoi d'une publication en tâche de fond.

## 1. Connexion

- Sur la page de connexion, un bouton **« Se connecter avec Mastodon »** et un champ « Votre serveur »
  (`mastodon.social`, ou une adresse complète `@nom@serveur`, dont on garde le domaine).
- Parcours : application enregistrée sur ce serveur si besoin (`POST /api/v1/apps`, droits limités :
  `read write:statuses write:media write:favourites write:follows`) → redirection vers le serveur → retour
  sur `/connexion/mastodon/retour` → échange du code → `verify_credentials` → compte distant trouvé ou créé
  (`RemoteAccounts::byHandle`) → session ouverte.
- Sécurité Symfony : un authentificateur `MastodonAuthenticator` (`AbstractAuthenticator`) à côté de `form_login`
  dans le pare-feu `main`. L'utilisateur de sécurité est l'entité `MastodonUser` (sans mot de passe, rôle
  `ROLE_MASTODON` en plus de ses rôles), fournie par un second provider (chaîne de providers). `CurrentAccount`
  rend son compte distant comme il rend aujourd'hui celui d'un `User`.
- Déconnexion : le jeton est révoqué sur le serveur (`/oauth/revoke`) et effacé.
- **Accès** : variable `MASTODON_LOGIN` de l'instance : `closed` (par défaut, personne), `open` (tout serveur), ou
  la liste des serveurs permis (`mastodon.social,piaille.fr`).
- **Écran** : mastodon.social, s'il est permis, se connecte d'un clic (bouton mis en avant) ; un autre serveur
  s'indique dans un champ (« Votre compte est sur un autre serveur ? »). Avec une liste de trois serveurs au plus,
  chacun a son bouton ; au-delà, le champ les propose en suggestion.

## 1 bis. Création d'un compte (MASTODON_SIGNUP)

- Variable `MASTODON_SIGNUP` (vide par défaut) : le serveur où l'on peut créer un compte depuis l'écran de
  connexion (`mastodon.social`), qui doit aussi être permis par `MASTODON_LOGIN`. Lien « Pas encore de compte
  Mastodon ? Créer un compte sur … » sous les boutons de connexion.
- Écran `/inscription/mastodon` : nom d'utilisateur, adresse e-mail, mot de passe, date de naissance si le serveur
  fixe un âge minimal, motif s'il valide les inscriptions, règles du serveur (lues dans `/api/v2/instance`, gardées
  une heure) à accepter. Inscriptions fermées : lien vers la page d'inscription du serveur.
- Inscription par l'API du serveur (`MastodonSignup`) : jeton d'application (`client_credentials`, droit
  `write:accounts`, ajouté aux droits de l'application ; une application enregistrée avant est refaite), puis
  `POST /api/v1/accounts`. Les refus s'affichent sous les champs. L'adresse et le mot de passe ne font que passer.
- Le jeton du nouveau compte attend, chiffré, en session (24 heures au plus) ; « J'ai confirmé mon adresse » connecte
  le compte (`MastodonLogin::loginWithToken`) une fois l'e-mail confirmé (avant : 403, message).
- Débit : le serveur accepte 5 inscriptions par 30 minutes depuis l'adresse de l'instance ; ici, 3 par heure et par
  visiteur (`symfony/rate-limiter`, limiteur `mastodon_signup`).

## 2. Profil et fils

- Son profil est la page de profil d'un compte distant qui existe déjà. `BackfillAccount` importe son historique
  (six mois, 50 publications au plus, comme aujourd'hui) à chaque connexion, puis toutes les 30 minutes
  (`RefreshMastodonAccounts`, planificateur) : son serveur n'envoie ses nouvelles publications qu'aux serveurs de
  ses abonnés. La boîte de réception garde les publications d'un compte piloté d'ici (`PilotedAccounts`), même
  sans abonné local.
- Pas de modification du profil sur Socialize : un lien renvoie vers les réglages de son serveur Mastodon.
- Fil d'accueil : les publications des comptes qu'elle suit et que Socialize connaît. Ce sont ses
  abonnements aux comptes Socialize (connus par les `Follow` reçus), et les comptes distants déjà connus.
  Un fil complet de Mastodon n'est pas visé.
- Visibilité : les règles actuelles des comptes distants s'appliquent. Une publication réservée aux abonnés n'est
  visible que si son compte est abonné. Les groupes d'abonnés d'un compte Socialize s'appliquent aussi.

## 3. Stories des comptes Socialize

- Le compte Mastodon voit, dans la barre des stories et sur les profils, les stories des comptes Socialize
  qu'il suit, avec les règles de visibilité actuelles appliquées à son compte distant : abonnés, groupes
  d'abonnés, compte bloqué. Les abonnements de comptes distants sont déjà enregistrés par la fédération : à
  vérifier que le calcul de visibilité des stories les prend bien en compte.
- Interactions disponibles aujourd'hui sur une story : la marquer comme vue (`StoryActions::markSeen`). La vue
  est enregistrée en local, comme pour un compte Socialize ; l'auteur voit qui a vu sa story.
- Les stories ne sont pas fédérées : rien ne part vers Mastodon. Si des réactions aux stories sont ajoutées plus
  tard (J'aime, réponse), elles restent locales pour un compte Mastodon. Une réponse pourrait aussi partir en
  message privé Mastodon mentionnant l'auteur.

## 4. Interactions

Dans `SocialActions` (follow, toggleLike, comment, deleteComment), quand le compte qui agit est un compte distant,
l'action passe d'abord par l'interface `PilotedActions` du cœur, que `MastodonActions` implémente, au lieu
d'émettre une activité signée :

- **J'aime** : statut retrouvé sur son serveur (`/api/v2/search?q=<uri ActivityPub>&resolve=true`), puis
  `favourite` / `unfavourite`. J'aime local enregistré aussitôt, unique par compte et publication.
- **Commentaire** : `POST /api/v1/statuses` avec `in_reply_to_id`, en mentionnant l'auteur (comme une application
  Mastodon) ; commentaire local enregistré avec l'`uri` renvoyé. Visibilité : publique (ou non listée) pour une
  publication publique (ou non listée), message direct à l'auteur sinon, pour que les abonnés du compte Mastodon
  ne voient rien d'une publication réservée.
- **Abonnement** : `POST /api/v1/accounts/:id/follow`. Pour un compte Socialize qui valide ses abonnés, la
  demande suit le parcours actuel.
- En cas d'échec (jeton révoqué, serveur injoignable) : l'action locale est annulée, et un message propose de se
  reconnecter.

## 5. Publication

- Page dédiée (`/publier/mastodon`, bouton « Publier » du menu), avec l'interface de l'écran de création (aperçus,
  ordre, textes alternatifs) : médias de l'appareil seulement (pas d'Immich ni de Synology Photos, dont les
  réglages se font sur un compte d'ici), texte, visibilité (public, non listé, abonnés) et avertissement. Pas de
  story, de groupes d'abonnés, de musique ni de lieu.
- Limites lues dans `/api/v2/instance` de son serveur (gardées un jour) et vérifiées avant l'envoi : nombre de
  médias, longueur du texte, formats et tailles des images et vidéos.
- Envoi en tâche de fond (`PublishToMastodon`, file Messenger `async`) : les originaux sont envoyés tels quels à
  `/api/v2/media` (son serveur les convertit lui-même), avec leur texte alternatif, en attendant le traitement d'une
  vidéo (3 minutes au plus) ; puis le statut (`/api/v1/statuses`, avec `media_ids` et la clé d'idempotence de
  l'envoi). Les fichiers attendent dans le stockage partagé avec les workers, et sont supprimés ensuite.
- Suivi (`MastodonPublication`) : sur son profil, l'auteur voit les envois en cours et les échecs du dernier jour,
  avec la raison. Une fois publiée, la publication revient par l'historique de son compte. Une publication
  « abonnés » n'y revient que si son serveur l'envoie ici (un abonné sur l'instance).
- Suppression d'une de ses publications depuis Socialize : d'abord sur son serveur (`DELETE /api/v1/statuses`).
  La modification n'est pas proposée (elle se fait sur Mastodon).

## 6. Import Instagram vers le compte Mastodon

- Un administrateur le permet dans Administration › Comptes, section « Comptes Mastodon » (rôle
  `ROLE_INSTAGRAM_IMPORTER` gardé dans `MastodonUser`).
- Même écran d'import (envoi de l'archive en plusieurs parties, analyse, choix par publication). Pour un compte
  Mastodon, `PilotedArchiveImporter` (module d'import) ne crée rien ici : chaque publication part sur son serveur par
  l'interface `PilotedPublishing` du cœur, que `MastodonPublishing` implémente.
- Différences avec l'import ordinaire :
  - pas de stories (ignorées) ni de groupes d'abonnés ;
  - pas de dates d'origine : la date Instagram est ajoutée au texte (« Publiée sur Instagram le … »), la légende
    étant raccourcie si besoin ;
  - visibilité : « Public, sans l'annoncer » devient non listé, « Abonnés seulement » abonnés, une publication
    privée ou archivée un message direct sans destinataire (visible par soi seul) ;
  - un carrousel plus long que la limite du serveur part en fil de messages (« (2/3) »…) ;
  - débit : une publication à la fois, puis une pause d'une minute par média (36 secondes au moins), pour rester
    sous les limites de Mastodon (30 médias par 30 minutes, 300 messages par 3 heures). Une erreur 429 met l'envoi
    en pause jusqu'à l'heure indiquée par le serveur. Le journal de l'import donne la fin estimée au lancement.
- Pas de doublon : un élément déjà publié (même empreinte, même compte) est sauté ; chaque message a sa clé
  d'idempotence.

## Ce qui n'est pas proposé à ces comptes

- Création de stories.
- Groupes d'abonnés, musique, lieu, Immich et Synology Photos, crosspost Instagram, réglages du profil,
  modification d'une publication, administration.
- Notifications Socialize et notifications sur l'appareil : Mastodon s'en charge déjà.

## Ordre de réalisation proposé

1. Connexion, déconnexion, profil (lecture seule), stories des comptes suivis.
2. J'aime, commentaires, abonnements.
3. Publication et suppression.
4. Import Instagram vers le compte Mastodon.

Les quatre étapes sont sur la branche `feature/connexion-mastodon`.

## Vérification

- **Tests fonctionnels** (`WebTestCase`) avec un `FakeMastodon` :
  - connexion : redirection vers le serveur avec PKCE et `state`, retour, compte distant créé, session ouverte ;
  - `state` invalide refusé, révocation à la déconnexion ;
  - J'aime et commentaire : appel API, enregistrement local, puis activité fédérée reçue sans doublon ;
  - publication : envoi des médias et du statut, limites de l'instance respectées ;
  - stories : une story « abonnés » visible par un compte Mastodon abonné, invisible pour un autre, vue
    enregistrée ; création de story refusée ;
  - import Instagram : refusé sans le droit ; avec le droit, statuts envoyés à Mastodon, aucun `Post` local,
    stories ignorées, cadence respectée, reprise sans doublon.
- **Essai réel** sur la production (la fédération exige une adresse publique) avec un compte Mastodon de test :
  connexion, story d'un compte suivi, J'aime et commentaire sur une publication Socialize, publication de
  photos et d'une vidéo, petit import Instagram, puis vérification sur Mastodon et sur Socialize.
- Avant chaque réintégration : `make quality` et `make test`.

## Points à trancher plus tard

- Contenu du fil d'accueil de ces comptes.
- Autres logiciels compatibles avec l'API Mastodon (Pixelfed, GoToSocial, Akkoma) : même code, avec
  quelques différences à vérifier.
