import { Controller } from '@hotwired/stimulus';

/*
 * Case dépendante : « dependent » n'est utilisable que si « source » est cochée (sinon désactivée et décochée).
 * Ex. Administration › Nouveau compte : l'import Instagram suppose le droit de publier.
 */
export default class extends Controller {
    static targets = ['source', 'dependent'];

    connect() {
        this.update();
    }

    update() {
        const allowed = this.sourceTarget.checked;
        this.dependentTarget.disabled = !allowed;
        if (!allowed) this.dependentTarget.checked = false;
        this.dependentTarget.closest('label')?.classList.toggle('off', !allowed);
    }
}
