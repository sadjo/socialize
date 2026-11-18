/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Suite d'une photothèque (Immich, Synology Photos) : le cadre Turbo de la page suivante se charge dès qu'il
 * approche du bas de la liste défilante. Le chargement « lazy » de Turbo seul ne suffit pas ici : il observe la
 * fenêtre, pas la liste, et se déclenche mal selon le navigateur. Le lien « Voir la suite » du cadre reste le
 * recours manuel.
 */
export default class extends Controller {
    connect() {
        const root = this.element.closest('.imm-scroll');
        this.observer = new IntersectionObserver((entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) return;
            this.observer.disconnect();
            // « eager » : Turbo charge aussitôt l'adresse du cadre.
            this.element.loading = 'eager';
        }, { root, rootMargin: '0px 0px 600px 0px' });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
    }
}
