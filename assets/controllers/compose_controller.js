import { Controller } from '@hotwired/stimulus';

const MAX_MEDIA = 20;

/*
 * Écran de création : aperçu des fichiers choisis, un champ de texte alternatif
 * par média, compteur de la légende.
 */
export default class extends Controller {
    static targets = ['input', 'previews', 'alts', 'caption', 'counter', 'drop', 'submit'];
    static values = { prototype: String };

    connect() {
        this.urls = [];
        this.inputTarget.addEventListener('change', () => this.render());
        this.captionTarget.addEventListener('input', () => this.count());
        ['dragover', 'dragenter'].forEach((type) => this.dropTarget.addEventListener(type, (event) => {
            event.preventDefault();
            this.dropTarget.classList.add('over');
        }));
        ['dragleave', 'drop'].forEach((type) => this.dropTarget.addEventListener(type, () => this.dropTarget.classList.remove('over')));
        this.dropTarget.addEventListener('drop', (event) => {
            event.preventDefault();
            this.inputTarget.files = event.dataTransfer.files;
            this.render();
        });
        this.element.addEventListener('submit', () => {
            this.submitTarget.disabled = true;
            this.submitTarget.textContent = 'Envoi en cours…';
        });
        this.count();
    }

    disconnect() {
        this.urls.forEach((url) => URL.revokeObjectURL(url));
    }

    render() {
        this.urls.forEach((url) => URL.revokeObjectURL(url));
        this.urls = [];
        this.previewsTarget.replaceChildren();
        this.altsTarget.querySelector('[data-prototype], div')?.replaceChildren();

        const files = [...this.inputTarget.files].slice(0, MAX_MEDIA);
        files.forEach((file, index) => {
            const url = URL.createObjectURL(file);
            this.urls.push(url);

            const preview = document.createElement('div');
            preview.className = 'preview';
            const frame = document.createElement('div');
            frame.className = 'th';
            const media = document.createElement(file.type.startsWith('video/') ? 'video' : 'img');
            media.src = url;
            if (media.tagName === 'VIDEO') { media.muted = true; media.playsInline = true; } else { media.alt = ''; }
            frame.appendChild(media);
            preview.appendChild(frame);

            const field = this.altField(index);
            field.placeholder = file.type.startsWith('video/') ? 'Décrivez la vidéo' : 'Texte alternatif : décrivez la photo';
            field.setAttribute('aria-label', `Texte alternatif du média ${index + 1}`);
            preview.appendChild(field);
            this.previewsTarget.appendChild(preview);
        });

        if (this.inputTarget.files.length > MAX_MEDIA) {
            const warning = document.createElement('p');
            warning.className = 'field-errors';
            warning.textContent = `Une publication contient au plus ${MAX_MEDIA} médias : seuls les ${MAX_MEDIA} premiers seront gardés.`;
            this.previewsTarget.prepend(warning);
        }
    }

    altField(index) {
        const template = document.createElement('template');
        template.innerHTML = this.prototypeValue.replace(/__name__/g, index).trim();
        const input = template.content.firstElementChild;
        input.classList.add('inp');
        return input;
    }

    count() {
        this.counterTarget.textContent = `${this.captionTarget.value.length.toLocaleString('fr-FR')} / 2 200`;
    }
}
