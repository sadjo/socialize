import { Controller } from '@hotwired/stimulus';

/* Copie un texte (lien, adresse du fédivers) dans le presse-papiers. */
export default class extends Controller {
    static values = { text: String };

    async copy() {
        const label = this.element.getAttribute('aria-label');
        try {
            await navigator.clipboard.writeText(this.textValue);
            this.element.setAttribute('aria-label', 'Copié');
            this.element.classList.add('copied');
        } catch {
            window.prompt('Copiez ce texte :', this.textValue);
        }
        setTimeout(() => {
            this.element.classList.remove('copied');
            if (label) this.element.setAttribute('aria-label', label);
        }, 1500);
    }
}
