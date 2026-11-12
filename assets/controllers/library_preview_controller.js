import { Controller } from '@hotwired/stimulus';
import { setSound, soundOn } from '../lib/sound.js';

/*
 * Aperçu d'une photothèque (Immich, Synology Photos) à la création d'une publication ou d'une story :
 * la loupe d'une vignette l'ouvre en grand (toucher la vignette elle-même la choisit) ; on passe à la précédente ou à la suivante (flèches, clavier, balayage),
 * et « Choisir » l'ajoute à la publication, ou l'en retire, comme un toucher sur sa vignette.
 *
 * Chaque vignette (cible « tile ») décrit son aperçu : data-preview-thumb (vignette, affichée aussitôt),
 * data-preview-image (image en grand), data-preview-video (vidéo relayée, si lisible), data-preview-kind
 * (« video » pour une vidéo sans lecture possible), data-preview-label et data-preview-details.
 * La liste suit les pages chargées au défilement : la vignette affichée est gardée visible dans la liste,
 * ce qui fait charger la suite en arrivant au bout.
 */
export default class extends Controller {
    static targets = ['tile'];

    connect() {
        // Nouvelle page chargée pendant l'aperçu : la flèche « suivante » réapparaît.
        this.onFrameLoad = () => { if (this.dialog) this.renderNav(); };
        this.element.addEventListener('turbo:frame-load', this.onFrameLoad);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-load', this.onFrameLoad);
        this.dialog?.close();
    }

    open({ currentTarget }) {
        const index = this.tileTargets.indexOf(currentTarget.closest('.imm-tile'));
        if (index < 0) return;
        this.index = index;
        this.dialog = this.build();
        document.body.appendChild(this.dialog);
        this.dialog.showModal();
        this.render();
    }

    build() {
        const dialog = document.createElement('dialog');
        dialog.className = 'lightbox lib-preview';
        dialog.setAttribute('aria-label', 'Aperçu');
        dialog.innerHTML = `
            <div class="lb-stage"></div>
            <button type="button" class="ib lb-close" aria-label="Fermer l’aperçu"><svg class="i" aria-hidden="true"><use href="#i-x"/></svg></button>
            <span class="lb-count num" aria-live="polite"></span>
            <button type="button" class="ib lb-prev" aria-label="Photo précédente"><svg class="i" aria-hidden="true"><use href="#i-left"/></svg></button>
            <button type="button" class="ib lb-next" aria-label="Photo suivante"><svg class="i" aria-hidden="true"><use href="#i-right"/></svg></button>
            <div class="lb-bar">
                <div class="lb-info"><b class="lb-label"></b><span class="lb-details"></span><span class="lb-note" role="status"></span></div>
                <button type="button" class="btn lb-select" aria-pressed="false"></button>
            </div>`;

        dialog.addEventListener('click', (e) => {
            if (e.target.closest('.lb-close') || e.target === dialog || e.target.classList.contains('lb-stage')) dialog.close();
            else if (e.target.closest('.lb-prev')) this.go(-1);
            else if (e.target.closest('.lb-next')) this.go(1);
            else if (e.target.closest('.lb-select')) this.toggle();
        });
        dialog.addEventListener('keydown', (e) => {
            // Les flèches restent à la vidéo quand elle a le focus (avance et recul).
            if (e.target.closest('video')) return;
            if (e.key === 'ArrowLeft') { e.preventDefault(); this.go(-1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); this.go(1); }
        });
        let startX = null;
        dialog.addEventListener('touchstart', (e) => { startX = e.target.closest('.lb-bar') ? null : e.changedTouches[0].clientX; }, { passive: true });
        dialog.addEventListener('touchend', (e) => {
            if (startX === null) return;
            const delta = e.changedTouches[0].clientX - startX;
            startX = null;
            if (Math.abs(delta) > 50) this.go(delta < 0 ? 1 : -1);
        }, { passive: true });
        dialog.addEventListener('close', () => {
            dialog.querySelectorAll('video').forEach((video) => { video.pause(); video.removeAttribute('src'); video.load(); });
            dialog.remove();
            this.dialog = null;
            this.tileTargets[this.index]?.querySelector('.imm-zoom')?.focus({ preventScroll: true });
        });

        return dialog;
    }

    go(step) {
        const next = this.index + step;
        if (next < 0 || next >= this.tileTargets.length) return;
        this.index = next;
        this.render();
    }

    get tile() {
        return this.tileTargets[this.index];
    }

    render() {
        const tile = this.tile;
        const data = tile.dataset;
        const stage = this.dialog.querySelector('.lb-stage');
        stage.querySelectorAll('video').forEach((video) => { video.pause(); video.removeAttribute('src'); video.load(); });

        let element;
        let note = '';
        if (data.previewVideo) {
            element = document.createElement('video');
            element.controls = true;
            element.playsInline = true;
            element.loop = true;
            element.muted = !soundOn();
            if (data.previewImage) element.poster = data.previewImage;
            element.src = data.previewVideo;
            element.addEventListener('volumechange', () => { if (soundOn() === element.muted) setSound(!element.muted); });
            element.addEventListener('error', () => { this.note('La vidéo ne peut pas être lue ici. Elle peut tout de même être choisie.'); });
            element.play().catch(() => { element.muted = true; element.play().catch(() => {}); });
        } else if (data.previewThumb || data.previewImage) {
            // Vignette tout de suite, agrandie à la taille de l'écran ; remplacée par l'image en grand une fois
            // celle-ci prête à s'afficher (sans passage par un écran vide).
            element = new Image();
            element.decoding = 'async';
            element.draggable = false;
            element.src = data.previewThumb || data.previewImage;
            if (data.previewImage && data.previewImage !== element.src) {
                const large = new Image();
                large.src = data.previewImage;
                large.decode().then(() => { if (this.tile === tile) element.src = large.src; }).catch(() => {});
            }
            if (data.previewKind === 'video') note = 'Aperçu de la vidéo indisponible : le NAS n’en a pas encore de version lisible. Elle peut tout de même être choisie.';
        } else {
            element = document.createElement('p');
            element.textContent = 'Aperçu indisponible.';
        }
        element.classList.add('lb-media');
        if (data.previewLabel) element.setAttribute('aria-label', data.previewLabel);
        stage.replaceChildren(element);

        this.dialog.querySelector('.lb-label').textContent = data.previewLabel || '';
        this.dialog.querySelector('.lb-details').textContent = data.previewDetails || '';
        this.note(note);
        this.renderNav();
        this.renderSelect();
        this.reveal(tile);

        // Image suivante préchargée.
        const next = this.tileTargets[this.index + 1]?.dataset;
        if (next?.previewImage && !next.previewVideo) new Image().src = next.previewImage;
    }

    renderNav() {
        const count = this.tileTargets.length;
        this.dialog.querySelector('.lb-prev').hidden = this.index === 0;
        this.dialog.querySelector('.lb-next').hidden = this.index >= count - 1;
        this.dialog.querySelector('.lb-count').textContent = `${this.index + 1} / ${count}`;
    }

    renderSelect() {
        const chosen = this.tile.querySelector('.imm-pick')?.getAttribute('aria-pressed') === 'true';
        const button = this.dialog.querySelector('.lb-select');
        button.setAttribute('aria-pressed', String(chosen));
        button.classList.toggle('pri', !chosen);
        button.innerHTML = chosen
            ? '<svg class="i xs" aria-hidden="true"><use href="#i-check"/></svg>Choisie · Retirer'
            : 'Choisir';
    }

    // Même effet qu'un toucher sur la vignette (contrôleur compose) : la sélection reste unique.
    toggle() {
        const sel = this.tile.querySelector('.imm-pick');
        if (!sel) return;
        const before = sel.getAttribute('aria-pressed') === 'true';
        sel.click();
        const after = sel.getAttribute('aria-pressed') === 'true';
        this.note(!before && !after ? 'Le nombre maximal de médias est atteint : retirez-en un pour en choisir un autre.' : '');
        this.renderSelect();
    }

    note(text) {
        const note = this.dialog?.querySelector('.lb-note');
        if (note) note.textContent = text;
    }

    // La vignette affichée reste visible dans la liste (et la suite de la liste se charge en arrivant au bout).
    reveal(tile) {
        const list = tile.closest('.imm-scroll');
        if (!list) return;
        const t = tile.getBoundingClientRect();
        const l = list.getBoundingClientRect();
        if (t.top < l.top || t.bottom > l.bottom) list.scrollTop += t.top - l.top - (l.height - t.height) / 2;
    }
}
