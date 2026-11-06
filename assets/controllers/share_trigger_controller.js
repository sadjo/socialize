import { Controller } from '@hotwired/stimulus';

/* Bouton « Partager » d'une publication : demande l'ouverture de la feuille de partage (voir share_controller). */
export default class extends Controller {
    static values = { url: String, title: String, text: String, media: String, video: Boolean };

    open() {
        window.dispatchEvent(new CustomEvent('socialize:share', {
            detail: { url: this.urlValue, title: this.titleValue, text: this.textValue, media: this.mediaValue, video: this.videoValue },
        }));
    }
}
