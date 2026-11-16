import { Controller } from '@hotwired/stimulus';

/* Bouton Retour de l'application ouverte depuis l'écran d'accueil (sans barre du navigateur). */
export default class extends Controller {
    back() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.assign('/');
        }
    }
}
