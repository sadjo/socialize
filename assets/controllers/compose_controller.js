import { Controller } from '@hotwired/stimulus';

/*
 * Sélection des médias d'une publication ou d'une story.
 * - Chaque nouvelle sélection s'ajoute aux médias déjà choisis.
 * - Les médias se retirent (✕) et se réordonnent (glisser-déposer, ou ◀ ▶ au clavier et sur mobile).
 * - En modification, les médias existants sont dans la liste ; l'ordre final part dans un champ caché
 *   (jetons « m-<id> » pour un média existant, « n-<i> » pour le i-ème nouveau fichier).
 * Le champ fichier est reconstruit à chaque changement, dans l'ordre choisi.
 */
export default class extends Controller {
    static targets = ['input', 'previews', 'caption', 'counter', 'drop', 'submit', 'order', 'empty'];
    static values = {
        max: { type: Number, default: 20 },
        altName: { type: String, default: '' },
        existing: { type: Array, default: [] },
        captionMax: { type: Number, default: 2200 },
    };

    connect() {
        this.items = this.existingValue.map((m) => ({ kind: 'existing', token: m.token, src: m.src, video: m.video, alt: m.alt || '' }));
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

        this.count();
        this.render();
    }

    disconnect() {
        this.items.forEach((item) => item.url && URL.revokeObjectURL(item.url));
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
        const item = this.items.splice(Number(event.currentTarget.dataset.index), 1)[0];
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
    }

    preview(item, index, count) {
        const node = document.createElement('div');
        node.className = 'preview';
        node.draggable = true;
        node.dataset.index = index;
        node.dataset.action = 'dragstart->compose#dragStart dragover->compose#dragOver drop->compose#dragDrop dragend->compose#dragEnd';

        const frame = document.createElement('div');
        frame.className = 'th';
        // Média déjà publié : sa vignette (une image, même pour une vidéo) ; nouveau fichier : aperçu local.
        const asVideo = item.video && item.kind !== 'existing';
        const media = document.createElement(asVideo ? 'video' : 'img');
        if (item.src) media.src = item.src; // vidéo encore en préparation : pas encore de vignette
        if (asVideo) { media.muted = true; media.playsInline = true; media.preload = 'metadata'; } else { media.alt = ''; }
        media.draggable = false;
        const badge = document.createElement('span');
        badge.className = 'order-badge num';
        badge.textContent = String(index + 1);
        frame.append(media, badge);
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
