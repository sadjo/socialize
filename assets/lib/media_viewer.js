import { setSound, soundOn } from './sound.js';

/*
 * Visionneuse plein écran des écrans de création : une suite de médias, vue un par un (flèches, clavier,
 * balayage), avec la date et le lieu en bas. Sert à la liste d'une photothèque (Immich, Synology Photos ;
 * bouton « Choisir ») et aux médias déjà choisis pour la publication ou la story.
 *
 * Un média (« entrée ») : { thumb, image, video, label, details, note } ; thumb s'affiche aussitôt, agrandi,
 * puis image le remplace une fois prête ; video, si présent, est lu à la place.
 */

/** Entrée d'une vignette de photothèque (attributs data-preview-*). */
export function tileEntry(tile) {
    const data = tile.dataset;
    return {
        thumb: data.previewThumb || '',
        image: data.previewImage || '',
        video: data.previewVideo || '',
        label: data.previewLabel || '',
        details: data.previewDetails || '',
        note: data.previewKind === 'video' && !data.previewVideo
            ? 'Aperçu de la vidéo indisponible : le NAS n’en a pas encore de version lisible. Elle peut tout de même être choisie.'
            : '',
    };
}

function stop(root) {
    root.querySelectorAll('video').forEach((video) => { video.pause(); video.removeAttribute('src'); video.load(); });
}

export class MediaViewer {
    /**
     * @param {object}   options
     * @param {function} options.entries  liste des entrées, relue à chaque passage (elle peut s'allonger)
     * @param {number}   options.index    entrée affichée d'abord
     * @param {object}   [options.select] bouton « Choisir » : { chosen(index), toggle(index) → message d'erreur ou '' }
     * @param {function} [options.onShow] appelé avec l'index affiché
     * @param {function} [options.onClose]
     */
    constructor({ entries, index, select = null, onShow = () => {}, onClose = () => {} }) {
        this.entries = entries;
        this.index = index;
        this.select = select;
        this.onShow = onShow;
        this.onClose = onClose;
    }

    open() {
        this.dialog = this.build();
        document.body.appendChild(this.dialog);
        this.dialog.showModal();
        this.render();
    }

    close() {
        this.dialog?.close();
    }

    build() {
        const dialog = document.createElement('dialog');
        dialog.className = 'lightbox lib-preview';
        dialog.setAttribute('aria-label', 'Aperçu');
        dialog.innerHTML = `
            <div class="lb-stage"></div>
            <button type="button" class="ib lb-close" aria-label="Fermer l’aperçu"><svg class="i" aria-hidden="true"><use href="#i-x"/></svg></button>
            <span class="lb-count num" aria-live="polite"></span>
            <button type="button" class="ib lb-prev" aria-label="Média précédent"><svg class="i" aria-hidden="true"><use href="#i-left"/></svg></button>
            <button type="button" class="ib lb-next" aria-label="Média suivant"><svg class="i" aria-hidden="true"><use href="#i-right"/></svg></button>
            <div class="lb-bar">
                <div class="lb-info"><b class="lb-label"></b><span class="lb-details"></span><span class="lb-note" role="status"></span></div>
                ${this.select ? '<button type="button" class="btn lb-select" aria-pressed="false"></button>' : ''}
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
            stop(dialog);
            dialog.remove();
            this.dialog = null;
            this.onClose(this.index);
        });

        return dialog;
    }

    go(step) {
        const next = this.index + step;
        if (next < 0 || next >= this.entries().length) return;
        this.index = next;
        this.render();
    }

    render() {
        const entries = this.entries();
        const entry = entries[this.index];
        if (!entry) { this.close(); return; }
        const stage = this.dialog.querySelector('.lb-stage');
        stop(stage);

        let element;
        if (entry.video) {
            element = document.createElement('video');
            element.controls = true;
            element.playsInline = true;
            element.loop = true;
            element.muted = !soundOn();
            if (entry.image) element.poster = entry.image;
            element.src = entry.video;
            element.addEventListener('volumechange', () => { if (soundOn() === element.muted) setSound(!element.muted); });
            element.addEventListener('error', () => { this.note('La vidéo ne peut pas être lue ici.'); });
            element.play().catch(() => { element.muted = true; element.play().catch(() => {}); });
        } else if (entry.thumb || entry.image) {
            // Vignette tout de suite, agrandie à la taille de l'écran ; remplacée par l'image en grand une fois
            // celle-ci prête à s'afficher (sans passage par un écran vide).
            element = new Image();
            element.decoding = 'async';
            element.draggable = false;
            element.src = entry.thumb || entry.image;
            if (entry.image && entry.image !== (entry.thumb || entry.image)) {
                const large = new Image();
                large.src = entry.image;
                const shown = this.index;
                large.decode().then(() => { if (this.dialog && this.index === shown) element.src = large.src; }).catch(() => {});
            }
        } else {
            element = document.createElement('p');
            element.textContent = 'Aperçu indisponible.';
        }
        element.classList.add('lb-media');
        if (entry.label) element.setAttribute('aria-label', entry.label);
        stage.replaceChildren(element);

        this.dialog.querySelector('.lb-label').textContent = entry.label;
        this.dialog.querySelector('.lb-details').textContent = entry.details;
        this.note(entry.note);
        this.renderNav();
        this.renderSelect();
        this.onShow(this.index);

        // Image suivante préchargée.
        const next = entries[this.index + 1];
        if (next?.image && !next.video) new Image().src = next.image;
    }

    renderNav() {
        if (!this.dialog) return;
        const count = this.entries().length;
        this.dialog.querySelector('.lb-prev').hidden = this.index === 0;
        this.dialog.querySelector('.lb-next').hidden = this.index >= count - 1;
        this.dialog.querySelector('.lb-count').textContent = `${this.index + 1} / ${count}`;
    }

    renderSelect() {
        const button = this.dialog.querySelector('.lb-select');
        if (!button) return;
        const chosen = this.select.chosen(this.index);
        button.setAttribute('aria-pressed', String(chosen));
        button.classList.toggle('pri', !chosen);
        button.innerHTML = chosen ? '<svg class="i xs" aria-hidden="true"><use href="#i-check"/></svg>Choisie · Retirer' : 'Choisir';
    }

    toggle() {
        if (!this.select) return;
        this.note(this.select.toggle(this.index) || '');
        this.renderSelect();
    }

    note(text) {
        const note = this.dialog?.querySelector('.lb-note');
        if (note) note.textContent = text || '';
    }
}
