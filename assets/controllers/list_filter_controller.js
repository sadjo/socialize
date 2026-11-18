/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/* Filtre une liste (abonnés d'un groupe…) au fil de la saisie, d'après le texte de chaque élément (data-text). */
export default class extends Controller {
    static targets = ['item'];

    filter({ target }) {
        const query = target.value.trim().toLowerCase();
        this.itemTargets.forEach((item) => {
            item.hidden = query !== '' && !(item.dataset.text || '').includes(query);
        });
    }
}
