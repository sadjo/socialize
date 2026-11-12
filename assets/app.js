// Point d'entrée JavaScript de Socialize, chargé par AssetMapper via importmap().
import './stimulus_bootstrap.js';
import './styles/app.css';

// Zoom fixe sur iPhone : Safari ignore « user-scalable=no » pour le pincement, on l'empêche donc ici.
for (const type of ['gesturestart', 'gesturechange', 'gestureend']) {
    document.addEventListener(type, (event) => event.preventDefault(), { passive: false });
}

/*
 * iPhone et iPad : une vidéo affichée par une navigation Turbo ne se lance pas toute seule (Safari ne l'autorise
 * qu'après un rechargement complet). Les visites (liens, redirection après un formulaire) y chargent donc la page
 * entièrement ; J'aime, commentaires, cadres et flux Turbo restent inchangés.
 */
const ios = /iP(hone|ad|od)/.test(navigator.userAgent) || ('MacIntel' === navigator.platform && navigator.maxTouchPoints > 1);
if (ios) {
    document.addEventListener('turbo:before-visit', (event) => {
        event.preventDefault();
        window.location.assign(event.detail.url);
    });
}
