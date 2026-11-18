/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/* Choix de visibilité : les groupes d'abonnés ne s'affichent que lorsque « Groupes » est choisi. */
export default class extends Controller {
    static targets = ['groups'];

    connect() {
        this.toggle();
    }

    toggle() {
        if (!this.hasGroupsTarget) return;
        const chosen = this.element.querySelector('input[type="radio"]:checked');
        this.groupsTarget.hidden = chosen?.value !== 'groups';
    }
}
