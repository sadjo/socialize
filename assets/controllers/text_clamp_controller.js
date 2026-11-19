/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Publication texte dans un fil : coupée après six lignes (CSS), avec « Afficher plus » seulement si le texte dépasse.
 * Les libellés viennent de la page (« Afficher plus » au départ, « Afficher moins » une fois dépliée).
 */
export default class extends Controller {
    static targets = ['body', 'toggle'];

    connect() {
        this.more = this.toggleTarget.textContent;
        // Mesure après la mise en page (polices chargées) : le bouton n'apparaît que si du texte est caché.
        requestAnimationFrame(() => {
            this.toggleTarget.hidden = this.bodyTarget.scrollHeight <= this.bodyTarget.clientHeight + 2;
        });
    }

    toggle() {
        const open = this.element.classList.toggle('open');
        this.toggleTarget.textContent = open ? 'Afficher moins' : this.more;
    }
}
