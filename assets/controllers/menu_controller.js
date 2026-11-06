import { Controller } from '@hotwired/stimulus';

/* Menu déroulant (<details>) : se ferme au clic à l'extérieur, avec Échap ou après une action. */
export default class extends Controller {
    connect() {
        this.onDocumentClick = (event) => { if (!this.element.contains(event.target)) this.close(); };
        this.onKeydown = (event) => { if (event.key === 'Escape' && this.element.open) { this.close(); this.element.querySelector('summary')?.focus(); } };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
    }

    close() {
        this.element.open = false;
    }
}
