import { Controller } from '@hotwired/stimulus';

/*
 * Menu complet sur mobile (le menu de gauche n'y est pas affiché) : fenêtre au centre de l'écran,
 * fermée par ✕, Échap, un clic sur le fond ou après avoir suivi un lien.
 */
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }

    // Lien suivi : la fenêtre se ferme (la page suivante arrive par Turbo).
    followed(event) {
        if (event.target.closest('a, [popovertarget]')) this.close();
    }
}
