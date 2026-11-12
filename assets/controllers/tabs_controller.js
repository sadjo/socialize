import { Controller } from '@hotwired/stimulus';

/*
 * Onglets défilants (écran étroit) : l'onglet ouvert est amené au milieu de la bande, sans faire défiler la page.
 */
export default class extends Controller {
    connect() {
        const current = this.element.querySelector('.on');
        if (!current || this.element.scrollWidth <= this.element.clientWidth) return;
        this.element.scrollLeft = current.offsetLeft - (this.element.clientWidth - current.offsetWidth) / 2;
    }
}
