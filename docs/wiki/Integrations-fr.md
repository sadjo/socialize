# Intégrations

## Import Instagram

Avec le droit **Import Instagram**, un compte rapatrie tout son historique Instagram (*Import* dans le menu).

1. Dans Instagram : *Espace Comptes › Vos informations et autorisations › Télécharger vos informations*, format
   **JSON**, depuis le début, au moins *Contenu* (publications, stories). Instagram envoie un ou plusieurs `.zip`.
2. Dans Socialize : envoyez l'archive. Les grosses archives (jusqu'à 20 Go, en une ou plusieurs parties) partent par
   morceaux de 50 Mo et l'envoi **reprend** après une coupure.
3. L'archive est analysée en tâche de fond : publications, publications archivées, stories. Les messages privés ne
   sont jamais lus.
4. **Choix** : visibilité de tout l'import, puis réglage publication par publication (autre visibilité, *Archiver*
   ou *Ne pas importer*), et groupe d'abonnés par défaut facultatif.
5. Import : dates d'origine, carrousels dans l'ordre, EXIF réécrit dans les originaux, publications archivées dans
   les archives, stories dans les archives de stories. Relancer un import ne crée aucun doublon. Les vidéos sont
   converties une fois tous les éléments importés.

Par défaut (`public_quiet`), les publications publiques importées ne sont **pas annoncées** au fédivers, pour ne pas
inonder vos abonnés. Annoncez-les ensuite avec `app:import:announce` (voir [Commandes console](Commandes-console)).
Les mentions `@compte` des légendes importées mènent aux profils Instagram.

Une archive déjà sur le serveur s'importe en console :

```bash
docker compose exec app php bin/console app:import:instagram alice /media/instagram-1.zip /media/instagram-2.zip
```

## Crosspost vers Instagram

*Réglages › Instagram* : un compte autorisé à publier y connecte un compte Instagram **professionnel** (Créateur ou
Entreprise, exigé par l'API d'Instagram). Les règles choisissent les visibilités pour lesquelles *Publier aussi sur
Instagram* est coché d'avance (et si elles valent pour les stories) ; la case reste modifiable à chaque publication.

L'envoi se fait en tâche de fond quand les médias sont prêts : photos converties en JPEG et mises aux proportions
acceptées par des bandes (sans recadrage), vidéo seule en Reel (3 s à 15 min), carrousel jusqu'à 20 médias, story.
L'état s'affiche sur la publication, avec *Réessayer* en cas d'échec ; une publication déjà en ligne peut aussi partir
après coup. Avec le droit `instagram_manage_contents`, supprimer une publication ici supprime sa copie sur Instagram.

### Application Meta

Créez une application sur [developers.facebook.com](https://developers.facebook.com) (type *Business*). Chaque compte
qui publie renseigne la sienne dans *Réglages › Instagram › Application Meta* (identifiant, clé secrète chiffrée et
jamais réaffichée, identifiant de configuration le cas échéant). L'instance peut en fournir une par défaut avec
`INSTAGRAM_APP_ID`, `INSTAGRAM_APP_SECRET` et `INSTAGRAM_CONFIG_ID`.

- **Application récente (« connexion unifiée »)** : cas d'usage *Gérer les messages et le contenu sur Instagram*,
  portefeuille d'entreprise relié, configuration *Facebook Login for Business* avec un jeton d'utilisateur système
  (expiration « Jamais »), ressource *Compte Instagram* et droits `instagram_basic` et `instagram_content_publish`
  (plus `instagram_manage_contents` pour les suppressions). Saisissez l'ID de l'app, sa clé secrète et l'identifiant
  de configuration, et déclarez `https://<votre-instance>/reglages/instagram/retour` comme URI de redirection OAuth.
- **Application plus ancienne (« connexion Instagram »)** : ID et clé secrète de l'app Instagram, même adresse de
  retour, identifiant de configuration vide. Avant la validation par Meta, seuls les comptes Instagram **testeurs**
  peuvent se connecter.

Sans application Meta, ou pour un compte Instagram personnel, **Partager vers Instagram** (menu d'une publication,
*Mes stories*) ouvre le partage du téléphone avec les photos en JPEG et les vidéos, et copie la légende ; sur
ordinateur, les fichiers se téléchargent.

## Immich

[Immich](https://immich.app) est une photothèque auto-hébergée. *Réglages › Immich* : adresse du serveur et clé API
avec les droits `user.read`, `asset.read`, `asset.view`, `asset.download`, `asset.statistics`, `album.read`,
`album.create`, `albumAsset.create`. La clé est chiffrée et jamais réaffichée.

À la création, l'onglet **Immich** montre la photothèque par jour et par lieu, chargée au défilement, avec filtre par
album et sélecteur de date (*Aller au*). Un clic sur une vignette la sélectionne, la loupe ouvre l'aperçu en grand
(vidéos lues). Médias d'Immich et de l'appareil se mêlent dans une même publication ; légende et lieu sont préremplis.
À la publication, les originaux sont téléchargés avec leur EXIF (date, lieu, GPS, objectif). Les médias publiés
peuvent rejoindre un album Immich *Publié sur Socialize*. Photos live prises en charge : vignette fixe, aperçu animé,
choix de la version.

Le serveur Immich doit être joignable depuis le serveur de Socialize.

## Synology Photos

*Réglages › Synology Photos* : adresse du serveur Synology (DSM 7), identifiant et mot de passe DSM, et code à usage
unique si la double authentification est active. Ils ouvrent une session ; seule cette session est gardée, chiffrée —
jamais le mot de passe. L'écran de création propose alors l'onglet **Synology Photos** (espace personnel, espace
partagé, albums), avec les mêmes aperçus et sélections qu'Immich, photos live et médias à plusieurs fichiers compris.

La session dure tant qu'elle sert (DSM › Panneau de configuration › Sécurité › Minuteur de déconnexion, jusqu'à
45 jours d'inactivité) ; à son expiration, Socialize propose de se reconnecter. Le serveur doit être joignable depuis
Socialize (pas d'adresse QuickConnect). L'API de Synology Photos n'est pas documentée : une mise à jour de DSM peut la
changer.

## Openverse (musique)

La recherche de musique utilise l'API publique d'[Openverse](https://openverse.org), sans réglage. Les pré-écoutes
sont relayées par votre serveur (le navigateur ne contacte jamais le fournisseur) et rien n'est stocké avant la
publication : le morceau choisi est alors copié une fois sur l'instance en AAC et partagé par toutes les publications
qui l'utilisent. Les crédits (titre, artiste, licence, source) s'affichent sous l'auteur et figurent dans la note
ActivityPub.
