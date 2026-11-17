import { Controller } from '@hotwired/stimulus';

/* Image du profil changée d'un clic : l'image est le libellé du champ fichier (le clic ouvre le choix du fichier) ;
   l'image choisie remplace aussitôt l'aperçu, et part avec le formulaire. */
export default class extends Controller {
    static targets = ['input', 'preview', 'note'];
    static values = { class: String };

    disconnect() {
        this.revoke();
    }

    preview() {
        const file = this.inputTarget.files?.[0];
        if (!file) return;
        this.revoke();
        this.url = URL.createObjectURL(file);
        const img = document.createElement('img');
        img.alt = '';
        img.className = this.classValue;
        // Format que le navigateur ne sait pas afficher (HEIC…) : l'ancien aperçu reste, le message suffit.
        img.addEventListener('load', () => this.previewTarget.replaceChildren(img), { once: true });
        img.src = this.url;
        this.element.classList.add('picked');
        if (this.hasNoteTarget) this.noteTarget.hidden = false;
    }

    revoke() {
        if (this.url) URL.revokeObjectURL(this.url);
        this.url = null;
    }
}
