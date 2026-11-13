import { Controller } from '@hotwired/stimulus';
import { setSound, soundOn } from '../lib/sound.js';
import { DOUBLE_TAP_DELAY } from '../lib/double_tap.js';

/*
 * Visionneuse des médias d'une publication : un clic sur une photo ou une vidéo l'ouvre en plein écran, dans
 * ses proportions d'origine (sans recadrage), et l'on passe d'un média à l'autre (flèches, clavier, balayage).
 * Un clic simple attend un instant : un double tap ou double-clic (J'aime) n'ouvre pas la visionneuse.
 */

export default class extends Controller {
    static targets = ['item'];

    disconnect() {
        clearTimeout(this.pending);
        this.dialog?.close();
    }

    open(event) {
        if (event.target.closest('button, a')) return;
        // Contenu sensible pas encore affiché : pas de visionneuse.
        if (this.element.querySelector('.cw-veil')) return;
        event.preventDefault();
        const index = this.itemTargets.indexOf(event.currentTarget);
        // Second clic rapproché : c'est un « J'aime » (like_controller), la visionneuse ne s'ouvre pas.
        if (this.pending) {
            clearTimeout(this.pending);
            this.pending = null;
            return;
        }
        this.pending = setTimeout(() => {
            this.pending = null;
            this.show(index);
        }, DOUBLE_TAP_DELAY);
    }

    show(index) {
        this.element.querySelectorAll('video').forEach((video) => video.pause());
        this.index = index;
        this.dialog = this.build();
        document.body.appendChild(this.dialog);
        this.dialog.showModal();
        this.render();
    }

    build() {
        const dialog = document.createElement('dialog');
        dialog.className = 'lightbox';
        dialog.innerHTML = `
            <div class="lb-stage"></div>
            <button type="button" class="ib lb-close" aria-label="Fermer"><svg class="i" aria-hidden="true"><use href="#i-x"/></svg></button>
            <span class="lb-count num" aria-live="polite"></span>
            <button type="button" class="ib lb-prev" aria-label="Média précédent"><svg class="i" aria-hidden="true"><use href="#i-left"/></svg></button>
            <button type="button" class="ib lb-next" aria-label="Média suivant"><svg class="i" aria-hidden="true"><use href="#i-right"/></svg></button>`;
        const multiple = this.itemTargets.length > 1;
        dialog.querySelector('.lb-prev').hidden = !multiple;
        dialog.querySelector('.lb-next').hidden = !multiple;
        dialog.querySelector('.lb-count').hidden = !multiple;

        dialog.addEventListener('click', (e) => {
            if (e.target.closest('.lb-close') || e.target === dialog || e.target.classList.contains('lb-stage')) dialog.close();
            else if (e.target.closest('.lb-prev')) this.go(-1);
            else if (e.target.closest('.lb-next')) this.go(1);
        });
        dialog.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') { e.preventDefault(); this.go(-1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); this.go(1); }
        });
        let startX = null;
        dialog.addEventListener('touchstart', (e) => { startX = e.changedTouches[0].clientX; }, { passive: true });
        dialog.addEventListener('touchend', (e) => {
            if (startX === null) return;
            const delta = e.changedTouches[0].clientX - startX;
            startX = null;
            if (Math.abs(delta) > 50) this.go(delta < 0 ? 1 : -1);
        }, { passive: true });
        dialog.addEventListener('close', () => {
            dialog.querySelectorAll('video').forEach((video) => video.pause());
            dialog.remove();
            this.dialog = null;
            // Le carrousel de la publication reste sur le dernier média regardé.
            this.application.getControllerForElementAndIdentifier(this.element, 'carousel')?.go(this.index);
        });

        return dialog;
    }

    go(step) {
        const count = this.itemTargets.length;
        if (count < 2) return;
        this.index = (this.index + step + count) % count;
        this.render();
    }

    render() {
        const item = this.itemTargets[this.index];
        const data = item.dataset;
        const stage = this.dialog.querySelector('.lb-stage');
        stage.querySelectorAll('video').forEach((video) => video.pause());

        let element;
        if (data.lightboxType === 'video') {
            element = document.createElement('video');
            element.controls = true;
            element.playsInline = true;
            element.loop = true;
            element.muted = !soundOn();
            if (data.lightboxPoster) element.poster = data.lightboxPoster;
            element.src = data.lightboxSrc;
            element.addEventListener('volumechange', () => { if (soundOn() === element.muted) setSound(!element.muted); });
            element.play().catch(() => { element.muted = true; element.play().catch(() => {}); });
        } else {
            element = new Image();
            element.src = data.lightboxSrc;
            element.decoding = 'async';
            element.draggable = false;
        }
        element.alt = data.lightboxAlt || '';
        if (data.lightboxAlt) element.setAttribute('aria-label', data.lightboxAlt);
        // Taille d'origine (copie publique), réduite seulement pour tenir dans l'écran.
        if (data.lightboxWidth && data.lightboxHeight) {
            element.width = Number(data.lightboxWidth);
            element.height = Number(data.lightboxHeight);
        }
        element.className = 'lb-media';
        stage.replaceChildren(element);

        const count = this.itemTargets.length;
        this.dialog.setAttribute('aria-label', `${data.lightboxType === 'video' ? 'Vidéo' : 'Photo'} ${this.index + 1} sur ${count}`);
        this.dialog.querySelector('.lb-count').textContent = `${this.index + 1}/${count}`;

        // Préchargement de la photo suivante.
        const next = this.itemTargets[(this.index + 1) % count]?.dataset;
        if (next && next.lightboxType !== 'video') new Image().src = next.lightboxSrc;
    }
}
