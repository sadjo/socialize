/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { MediaViewer, tileEntry } from '../lib/media_viewer.js';

/*
 * Sélection des médias d'une publication ou d'une story, depuis l'appareil ou une photothèque (Immich, Synology Photos).
 * - Chaque nouvelle sélection s'ajoute aux médias déjà choisis.
 * - Les médias se retirent (✕) et se réordonnent (glisser-déposer, ou ◀ ▶ au clavier et sur mobile).
 * - En modification, les médias existants sont dans la liste ; l'ordre final part dans un champ caché
 *   (jetons « m-<id> » pour un média existant, « n-<i> » pour le i-ème nouveau fichier, « i-… » ou « s-… » pour
 *   un média d'une photothèque).
 * Le champ fichier est reconstruit à chaque changement, dans l'ordre choisi.
 * La loupe d'un aperçu ouvre les médias choisis en grand, dans leur ordre (MediaViewer).
 */
export default class extends Controller {
    static targets = ['input', 'previews', 'caption', 'counter', 'drop', 'submit', 'order', 'empty', 'location', 'sourceTab', 'deviceSource', 'librarySource'];
    static values = {
        max: { type: Number, default: 20 },
        altName: { type: String, default: '' },
        existing: { type: Array, default: [] },
        captionMax: { type: Number, default: 2200 },
    };

    connect() {
        this.items = this.existingValue.map((m) => ({ kind: 'existing', token: m.token, src: m.src, large: m.large || '', videoSrc: m.videoSrc || '', video: m.video, alt: m.alt || '' }));
        this.dragIndex = null;

        this.inputTarget.addEventListener('change', () => this.add([...this.inputTarget.files]));
        if (this.hasCaptionTarget) this.captionTarget.addEventListener('input', () => this.count());
        if (this.hasDropTarget) {
            ['dragover', 'dragenter'].forEach((type) => this.dropTarget.addEventListener(type, (event) => {
                event.preventDefault();
                this.dropTarget.classList.add('over');
            }));
            ['dragleave', 'drop'].forEach((type) => this.dropTarget.addEventListener(type, () => this.dropTarget.classList.remove('over')));
            this.dropTarget.addEventListener('drop', (event) => {
                event.preventDefault();
                this.add([...event.dataTransfer.files]);
            });
        }
        this.element.addEventListener('submit', () => {
            if (!this.hasSubmitTarget) return;
            this.submitTarget.disabled = true;
            this.submitTarget.textContent = 'Envoi en cours…';
        });

        // Nouvelles vignettes d'une photothèque (page suivante, autre album) : leur état coché suit la sélection.
        this.element.addEventListener('turbo:frame-load', () => this.markTiles());

        this.count();
        this.render();
    }

    /* Onglets « Appareil », « Immich », « Synology Photos ». */
    source({ params: { source } }) {
        this.sourceTabTargets.forEach((tab) => {
            const on = tab.dataset.composeSourceParam === source;
            tab.classList.toggle('on', on);
            tab.setAttribute('aria-selected', String(on));
        });
        if (this.hasDeviceSourceTarget) this.deviceSourceTarget.hidden = source !== 'device';
        this.librarySourceTargets.forEach((library) => { library.hidden = library.dataset.sourceName !== source; });
    }

    /*
     * Photothèque (celle de l'onglet d'où vient l'événement) : un album, affiché en entier, ou toute la photothèque
     * (pour Synology Photos : un de ses deux espaces), ouverte à une date : médias pris ce jour-là ou avant.
     * La date et l'espace ne sont proposés que sans album.
     */
    filterLibrary({ currentTarget }) {
        const library = currentTarget.closest('[data-library-url]');
        const frame = library?.querySelector('turbo-frame');
        if (!frame) return;
        const album = library.querySelector('[data-library-album]');
        const date = library.querySelector('[data-library-date]');
        const space = library.querySelector('[data-library-space]');
        const inAlbum = Boolean(album && album.value !== '');
        library.querySelectorAll('[data-library-without-album]').forEach((element) => { element.hidden = inAlbum; });
        if (inAlbum && date) date.value = '';
        const params = new URLSearchParams();
        if (inAlbum) params.set('album', album.value);
        else {
            if (space && space.value) params.set('espace', space.value);
            if (date && date.value) params.set('date', date.value);
        }
        const query = params.toString();
        frame.src = library.dataset.libraryUrl + (query ? `?${query}` : '');
        frame.closest('.imm-scroll')?.scrollTo({ top: 0 });
    }

    resetLibrary(event) {
        const date = event.currentTarget.closest('[data-library-url]')?.querySelector('[data-library-date]');
        if (date) date.value = '';
        this.filterLibrary(event);
    }

    /* Une vignette de photothèque ajoute le média à la sélection, ou l'en retire s'il y est déjà. */
    toggleLibrary({ params: { token, src, video, label, description, place }, currentTarget }) {
        // Une seule version d'une photo live (« <jeton> » fixe, « <jeton>-live ») : toucher la vignette retire celle
        // qui est choisie, quelle qu'elle soit.
        const base = (t) => t.replace(/-live$/, '');
        const index = this.items.findIndex((item) => base(item.token) === base(token));
        if (index >= 0) {
            this.items.splice(index, 1);
        } else if (this.items.length >= this.maxValue) {
            this.warn(`Vous pouvez ajouter ${this.maxValue} médias au plus.`);
            return;
        } else {
            // Aperçu en grand de la vignette (image, vidéo, date), repris par la loupe des médias choisis.
            const tile = currentTarget.closest('[data-library-preview-target="tile"]');
            this.items.push({ kind: 'library', token, src, video: Boolean(video), label: label || '', alt: '', preview: tile ? tileEntry(tile, { live: token.endsWith('-live') }) : null });
            // Légende et lieu préremplis depuis la photothèque, seulement s'ils sont encore vides.
            if (description && this.hasCaptionTarget && this.captionTarget.value.trim() === '') {
                this.captionTarget.value = description;
                this.count();
            }
            if (place && this.hasLocationTarget && this.locationTarget.value.trim() === '') this.locationTarget.value = place;
        }
        this.render();
    }

    markTiles() {
        const selected = new Set(this.items.map((item) => item.token));
        this.element.querySelectorAll('[data-compose-token-param]').forEach((tile) => {
            tile.setAttribute('aria-pressed', String(selected.has(tile.dataset.composeTokenParam)));
        });
    }

    disconnect() {
        this.viewer?.close();
        this.items.forEach((item) => item.url && URL.revokeObjectURL(item.url));
    }

    /* Loupe d'un aperçu : les médias choisis en grand, dans leur ordre. */
    zoom(event) {
        event.stopPropagation();
        this.viewer = new MediaViewer({
            entries: () => this.items.map((item, i) => this.entry(item, i)),
            index: Number(event.currentTarget.dataset.index),
            // Même effet que ✕ sous l'aperçu.
            remove: (i) => this.removeAt(i),
            onClose: (i) => {
                this.viewer = null;
                this.previewsTarget.querySelectorAll('.preview')[i]?.querySelector('.imm-zoom')?.focus({ preventScroll: true });
            },
        });
        this.viewer.open();
    }

    entry(item, index) {
        const position = `Média ${index + 1} sur ${this.items.length}`;
        if (item.kind === 'library' && item.preview) return { ...item.preview, label: item.preview.label || position, details: [item.label, item.preview.details].filter(Boolean).join(' · ') };
        if (item.kind === 'new') {
            return { thumb: item.video ? '' : item.url, image: item.video ? '' : item.url, video: item.video ? item.url : '', label: position, details: item.file.name, note: '' };
        }
        // Média déjà publié (modification) : copie publique en grand, et sa vidéo.
        return { thumb: item.src || '', image: item.large || item.src || '', video: item.videoSrc || '', label: position, details: item.alt || '', note: '' };
    }

    add(files) {
        const accepted = files.filter((file) => file.type.startsWith('image/') || file.type.startsWith('video/'));
        for (const file of accepted) {
            if (this.items.length >= this.maxValue) {
                this.warn(`Vous pouvez ajouter ${this.maxValue} médias au plus.`);
                break;
            }
            const url = URL.createObjectURL(file);
            this.items.push({ kind: 'new', file, url, src: url, video: file.type.startsWith('video/'), alt: '' });
        }
        this.render();
    }

    remove(event) {
        this.removeAt(Number(event.currentTarget.dataset.index));
    }

    removeAt(index) {
        const item = this.items.splice(index, 1)[0];
        if (item?.url) URL.revokeObjectURL(item.url);
        this.render();
    }

    left(event) { this.move(Number(event.currentTarget.dataset.index), -1); }
    right(event) { this.move(Number(event.currentTarget.dataset.index), 1); }

    move(index, delta) {
        const target = index + delta;
        if (target < 0 || target >= this.items.length) return;
        [this.items[index], this.items[target]] = [this.items[target], this.items[index]];
        this.render();
        this.previewsTarget.querySelectorAll('.preview')[target]?.querySelector(delta < 0 ? '[data-action$="#left"]' : '[data-action$="#right"]')?.focus();
    }

    dragStart(event) {
        this.dragIndex = Number(event.currentTarget.dataset.index);
        event.dataTransfer.effectAllowed = 'move';
        event.currentTarget.classList.add('dragging');
    }

    dragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }

    dragDrop(event) {
        event.preventDefault();
        const target = Number(event.currentTarget.dataset.index);
        if (this.dragIndex === null || this.dragIndex === target) return;
        const [item] = this.items.splice(this.dragIndex, 1);
        this.items.splice(target, 0, item);
        this.dragIndex = null;
        this.render();
    }

    dragEnd(event) {
        event.currentTarget.classList.remove('dragging');
        this.dragIndex = null;
    }

    /* Champ fichier, champ d'ordre et noms des textes alternatifs, dans l'ordre affiché. */
    sync() {
        const transfer = new DataTransfer();
        let newIndex = 0;
        const tokens = this.items.map((item) => {
            if (item.kind === 'new') {
                transfer.items.add(item.file);
                item.token = `n-${newIndex++}`;
            }
            return item.token;
        });
        this.inputTarget.files = transfer.files;
        if (this.hasOrderTarget) this.orderTarget.value = tokens.join(',');
    }

    altFieldName(item, index) {
        // En création, les textes alternatifs suivent l'ordre des fichiers (0, 1, 2…) ; en modification, les jetons.
        const key = this.hasOrderTarget ? item.token : String(index);
        return this.altNameValue.replace('__key__', key);
    }

    render() {
        this.sync();
        const count = this.items.length;
        this.previewsTarget.replaceChildren(...this.items.map((item, index) => this.preview(item, index, count)));
        if (this.hasEmptyTarget) this.emptyTarget.hidden = count > 0;
        this.markTiles();
    }

    preview(item, index, count) {
        const node = document.createElement('div');
        node.className = 'preview';
        node.draggable = true;
        node.dataset.index = index;
        node.dataset.action = 'dragstart->compose#dragStart dragover->compose#dragOver drop->compose#dragDrop dragend->compose#dragEnd';

        const frame = document.createElement('div');
        frame.className = 'th';
        // Média déjà publié ou d'une photothèque : sa vignette (une image, même pour une vidéo) ; nouveau fichier : aperçu local.
        const asVideo = item.video && item.kind === 'new';
        const media = document.createElement(asVideo ? 'video' : 'img');
        if (item.src) media.src = item.src; // vidéo encore en préparation : pas encore de vignette
        if (asVideo) { media.muted = true; media.playsInline = true; media.preload = 'metadata'; } else { media.alt = ''; }
        media.draggable = false;
        const badge = document.createElement('span');
        badge.className = 'order-badge num';
        badge.textContent = String(index + 1);
        const zoom = document.createElement('button');
        zoom.type = 'button';
        zoom.className = 'imm-zoom';
        zoom.draggable = false;
        zoom.dataset.action = 'compose#zoom';
        zoom.dataset.index = index;
        zoom.setAttribute('aria-label', `Voir en grand le média ${index + 1}`);
        zoom.innerHTML = '<svg class="i xs" aria-hidden="true"><use href="#i-zoom"/></svg>';
        frame.append(media, badge, zoom);
        if (item.kind === 'library') {
            const source = document.createElement('span');
            source.className = 'src-badge';
            source.textContent = item.video ? `${item.label} · vidéo` : item.label;
            frame.appendChild(source);
        }
        node.appendChild(frame);

        if (this.altNameValue) {
            const alt = document.createElement('input');
            alt.type = 'text';
            alt.className = 'inp';
            alt.name = this.altFieldName(item, index);
            alt.value = item.alt;
            alt.maxLength = 1500;
            alt.placeholder = item.video ? 'Décrire la vidéo' : 'Décrire la photo';
            alt.setAttribute('aria-label', `Texte alternatif du média ${index + 1}`);
            alt.addEventListener('input', () => { item.alt = alt.value; });
            node.appendChild(alt);
        }

        const tools = document.createElement('div');
        tools.className = 'preview-tools';
        tools.append(
            this.button('◀', `Placer le média ${index + 1} avant`, 'left', index, index === 0),
            this.button('▶', `Placer le média ${index + 1} après`, 'right', index, index === count - 1),
            this.button('✕', `Retirer le média ${index + 1}`, 'remove', index, false, 'danger'),
        );
        node.appendChild(tools);

        return node;
    }

    button(label, title, action, index, disabled, extra = '') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn sm ghost ${extra}`.trim();
        button.textContent = label;
        button.title = title;
        button.setAttribute('aria-label', title);
        button.dataset.action = `compose#${action}`;
        button.dataset.index = index;
        button.disabled = disabled;
        return button;
    }

    warn(message) {
        const warning = document.createElement('p');
        warning.className = 'field-errors';
        warning.textContent = message;
        this.previewsTarget.before(warning);
        setTimeout(() => warning.remove(), 5000);
    }

    count() {
        if (!this.hasCaptionTarget || !this.hasCounterTarget) return;
        this.counterTarget.textContent = `${this.captionTarget.value.length.toLocaleString('fr-FR')} / ${this.captionMaxValue.toLocaleString('fr-FR')}`;
    }
}
