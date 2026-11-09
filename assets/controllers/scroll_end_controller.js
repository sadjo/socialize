import { Controller } from '@hotwired/stimulus';

/*
 * Garde un bloc défilant sur son dernier élément (journal d'un import).
 * Le cadre de progression est remplacé à chaque mise à jour : le contrôleur se reconnecte
 * et revient en bas, sauf si l'utilisateur était remonté lire les lignes précédentes.
 */
let followed = true;

export default class extends Controller {
    connect() {
        if (followed) this.element.scrollTop = this.element.scrollHeight;
        this.onScroll = () => {
            followed = this.element.scrollHeight - this.element.scrollTop - this.element.clientHeight < 24;
        };
        this.element.addEventListener('scroll', this.onScroll, { passive: true });
    }

    disconnect() {
        this.element.removeEventListener('scroll', this.onScroll);
    }
}
