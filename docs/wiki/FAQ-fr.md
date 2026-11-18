# FAQ et dépannage

### Le conteneur s'arrête avec « APP_SECRET est obligatoire »
`APP_SECRET` est obligatoire en production : `openssl rand -hex 32`, à mettre dans `docker.env`.

### Caddy n'obtient pas de certificat
Les ports 80 et 443 doivent être joignables depuis Internet et le domaine doit pointer vers le serveur. Derrière un
reverse proxy, utilisez `SERVER_NAME=:80` (voir [Installation](Installation-fr#derrière-un-reverse-proxy)).

### Les visiteurs ne voient qu'un formulaire de connexion
C'est le réglage par défaut (`HOME_LOGIN_ONLY=1`). `HOME_LOGIN_ONLY=0` affiche l'accueil et les fils publics.

### Personne ne peut s'inscrire
Avec `REGISTRATION=invite`, seules les personnes invitées rejoignent l'instance : *Administration › Invitations*.
`REGISTRATION=open` ouvre les inscriptions.

### Un compte ne peut pas publier
Un nouveau compte n'a pas le droit de publier : accordez *Publication* dans *Administration › Comptes*.

### Les vidéos restent « en attente »
Les vidéos sont converties par les tâches de fond. Vérifiez que `WORKERS` ne vaut pas `0` (ou que les conteneurs de
workers tournent) et lisez les journaux : `docker compose logs app`. `messenger:stats` montre les messages en attente.

### Les invitations ne partent pas par e-mail
Réglez `MAILER_DSN` et `MAILER_FROM` ([Configuration](Configuration-fr#e-mails)) ; encodez les caractères spéciaux de
l'identifiant et du mot de passe SMTP. Le lien d'invitation peut toujours être copié et transmis à la main.

### Pas de notifications sur iPhone
Ajoutez d'abord le site à l'écran d'accueil (*Partager › Sur l'écran d'accueil*), ouvrez-le depuis là, puis activez
les notifications dans *Notifications › Sur cet appareil*. HTTPS est indispensable.

### Mes contacts Mastodon ne trouvent pas mon compte
Cherchez l'adresse complète `@vous@votre-domaine`. L'instance doit être joignable en HTTPS et `DEFAULT_URI` doit être
son adresse publique. Voir [Fédération › Dépannage](Federation-fr#dépannage).

### J'ai changé de nom de domaine et la fédération ne marche plus
Les autres serveurs reconnaissent les comptes à leur adresse : changer `DEFAULT_URI` crée de nouvelles identités.
Revenez à l'ancien domaine, ou repartez de zéro pour la fédération.

### J'ai perdu APP_SECRET
Les clés des comptes, les clés VAPID et les jetons de connexion ne peuvent plus être déchiffrés. Restaurez-le depuis
une sauvegarde de `docker.env` ; sinon, abonnés distants et services connectés seront à reconfigurer.

### Comment connaître ma version ?
En bas du menu de gauche, ou avec `docker compose images`.
