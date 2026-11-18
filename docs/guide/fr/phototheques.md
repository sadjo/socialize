# Photothèques Immich et Synology

Une photothèque connectée permet de choisir les médias d'une publication directement dans vos albums. Les originaux
sont copiés avec leurs métadonnées (date, lieu, appareil) au moment de publier.

## Immich

*Réglages › Immich* : adresse du serveur Immich et clé API, créée dans Immich (*Paramètres du compte › Clés API*) avec
les droits `user.read`, `asset.read`, `asset.view`, `asset.download`, `asset.statistics`, `album.read`,
`album.create` et `albumAsset.create`. La clé est chiffrée et jamais réaffichée.

À la création d'une publication, l'onglet **Immich** montre la photothèque par jour et par lieu, avec un filtre par
album et *Aller au* pour sauter à une date. Un clic sélectionne une vignette, la loupe ouvre l'aperçu en grand. Les
médias publiés peuvent rejoindre un album Immich *Publié sur Socialize*. Les photos live sont prises en charge.

## Synology Photos

*Réglages › Synology* : adresse du serveur Synology (DSM 7), identifiant et mot de passe DSM, et code à usage unique si
la double authentification est active. Seule la session ouverte est gardée, chiffrée, jamais le mot de passe.

L'onglet **Synology Photos** propose l'espace personnel, l'espace partagé et les albums, avec les mêmes aperçus et
sélections qu'Immich. Quand la session expire, Socialize propose de se reconnecter.

## Mélanger les sources

Médias de l'appareil, d'Immich et de Synology Photos se mêlent dans une même publication ; la légende et le lieu sont
préremplis depuis la photothèque quand ils y figurent.
