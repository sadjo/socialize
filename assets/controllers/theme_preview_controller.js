import { Controller } from '@hotwired/stimulus';

/*
 * Réglages › Apparence : le thème coché s'applique tout de suite à la page (attribut data-theme de <html>) pour voir
 * le rendu. Sans enregistrement, le thème du compte revient en quittant la page : Turbo garde <html> d'une page à
 * l'autre, l'aperçu resterait sinon affiché.
 */
export default class extends Controller {
    connect() {
        this.saved = document.documentElement.dataset.theme;
        this.submitted = false;
    }

    preview(event) {
        document.documentElement.dataset.theme = event.target.value;
    }

    submit() {
        this.submitted = true;
    }

    disconnect() {
        if (!this.submitted) document.documentElement.dataset.theme = this.saved;
    }
}
