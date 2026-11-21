import { Controller } from '@hotwired/stimulus';

/*
 * « Mes stories » : stories cochées pour être mises à la une ensemble. Affiche leur nombre, n'active le bouton
 * qu'avec au moins une story cochée et ne montre le champ du titre que pour une nouvelle story à la une.
 */
export default class extends Controller {
    static targets = ['box', 'count', 'submit', 'highlight', 'title'];

    connect() {
        this.update();
    }

    update() {
        const count = this.boxTargets.filter((box) => box.checked).length;
        if (this.hasCountTarget) {
            this.countTarget.textContent = count === 0
                ? 'Cochez les stories à mettre à la une.'
                : `${count} stor${count > 1 ? 'ies sélectionnées' : 'y sélectionnée'}`;
        }
        if (this.hasSubmitTarget) this.submitTarget.disabled = count === 0;
        if (this.hasTitleTarget && this.hasHighlightTarget) {
            const isNew = this.highlightTarget.value === '__new__';
            this.titleTarget.hidden = !isNew;
            this.titleTarget.required = isNew;
        }
        this.boxTargets.forEach((box) => box.closest('.story-tile')?.classList.toggle('picked', box.checked));
    }
}
