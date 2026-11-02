import { Controller } from '@hotwired/stimulus';

/* Avertissement de contenu : retire le voile au clic. */
export default class extends Controller {
    static targets = ['veil'];

    show() {
        this.veilTarget.remove();
    }
}
