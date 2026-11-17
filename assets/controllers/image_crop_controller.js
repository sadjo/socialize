import { Controller } from '@hotwired/stimulus';

const MAX_ZOOM = 4;

/* Image du profil changée d'un clic puis cadrée : l'image est le libellé du champ fichier (le clic ouvre le choix du
   fichier). L'image choisie s'ouvre dans un cadre aux proportions publiées : on la fait glisser, on zoome ; le cadre
   (« x,y,largeur,hauteur » en fractions de l'image) remplit un champ caché et le serveur découpe l'image. « Recadrer »
   rouvre le cadre sur la source gardée par le serveur, sans renvoyer le fichier. */
export default class extends Controller {
    static targets = ['input', 'crop', 'preview', 'panel', 'frame', 'image', 'zoom', 'note', 'adjust'];
    static values = { source: String, crop: String, aspect: { type: Number, default: 1 }, imageClass: String };

    connect() {
        this.move = this.move.bind(this);
        this.up = this.up.bind(this);
        this.resize = this.resize.bind(this);
        this.originalPreview = [...this.previewTarget.childNodes].map((node) => node.cloneNode(true));
        window.addEventListener('resize', this.resize);
    }

    disconnect() {
        window.removeEventListener('resize', this.resize);
        this.up();
        this.revoke();
    }

    /* Nouveau fichier choisi : il s'ouvre aussitôt dans le cadre. */
    pick() {
        const file = this.inputTarget.files?.[0];
        if (!file) return;
        this.revoke();
        this.fileUrl = URL.createObjectURL(file);
        this.cropTarget.value = '';
        this.load(this.fileUrl, null);
    }

    /* « Repositionner » / « Recadrer » : le fichier choisi, sinon la source gardée, au cadre actuel. */
    open() {
        const url = this.fileUrl || this.sourceValue;
        if (url) this.load(url, this.cropTarget.value || this.cropValue);
    }

    load(url, crop) {
        const image = this.imageTarget;
        image.onload = () => {
            this.panelTarget.hidden = false;
            this.layout(this.parse(crop));
            this.adjustTarget.hidden = false;
        };
        image.onerror = () => {
            // Format que le navigateur ne sait pas afficher (HEIC…) : le serveur cadrera l'image tout seul.
            this.panelTarget.hidden = true;
            this.say(this.fileUrl ? 'Aperçu impossible pour ce format : l’image sera cadrée automatiquement. Enregistrez pour la publier.' : 'Cette image ne peut pas être recadrée ici.');
        };
        image.src = url;
    }

    layout(crop) {
        const { naturalWidth: w, naturalHeight: h } = this.imageTarget;
        const W = this.frameTarget.clientWidth;
        const H = W / this.aspectValue;
        this.size = { W, H, w, h };
        this.base = Math.max(W / w, H / h);
        if (crop) {
            this.level = Math.min(MAX_ZOOM, Math.max(1, W / (crop.width * w) / this.base));
            const s = this.scale;
            this.x = -crop.x * w * s;
            this.y = -crop.y * h * s;
        } else {
            this.level = 1;
            this.x = (W - w * this.scale) / 2;
            this.y = (H - h * this.scale) / 2;
        }
        this.zoomTarget.value = String(this.level);
        this.render();
    }

    get scale() {
        return this.base * this.level;
    }

    render() {
        const { W, H, w, h } = this.size;
        const s = this.scale;
        this.x = Math.min(0, Math.max(W - w * s, this.x));
        this.y = Math.min(0, Math.max(H - h * s, this.y));
        Object.assign(this.imageTarget.style, { width: `${w * s}px`, height: `${h * s}px`, transform: `translate(${this.x}px, ${this.y}px)` });
        this.cropTarget.value = this.current().map((v) => v.toFixed(5)).join(',');
    }

    /* Cadre affiché, en fractions de l'image : x, y, largeur, hauteur. */
    current() {
        const { W, H, w, h } = this.size;
        const s = this.scale;
        return [-this.x / (w * s), -this.y / (h * s), Math.min(1, W / (w * s)), Math.min(1, H / (h * s))];
    }

    zoom() {
        this.zoomTo(Number(this.zoomTarget.value));
    }

    wheel(event) {
        event.preventDefault();
        this.zoomTo(this.level * (event.deltaY < 0 ? 1.08 : 1 / 1.08));
        this.zoomTarget.value = String(this.level);
    }

    /* Zoom autour du centre du cadre. */
    zoomTo(level) {
        const { W, H } = this.size;
        const before = this.scale;
        const cx = (W / 2 - this.x) / before;
        const cy = (H / 2 - this.y) / before;
        this.level = Math.min(MAX_ZOOM, Math.max(1, level));
        this.x = W / 2 - cx * this.scale;
        this.y = H / 2 - cy * this.scale;
        this.render();
    }

    down(event) {
        event.preventDefault();
        this.drag = { px: event.clientX, py: event.clientY, x: this.x, y: this.y };
        window.addEventListener('pointermove', this.move);
        window.addEventListener('pointerup', this.up);
        window.addEventListener('pointercancel', this.up);
    }

    move(event) {
        if (!this.drag) return;
        this.x = this.drag.x + event.clientX - this.drag.px;
        this.y = this.drag.y + event.clientY - this.drag.py;
        this.render();
    }

    up() {
        this.drag = null;
        window.removeEventListener('pointermove', this.move);
        window.removeEventListener('pointerup', this.up);
        window.removeEventListener('pointercancel', this.up);
    }

    resize() {
        if (this.panelTarget.hidden || !this.size) return;
        const [x, y, width, height] = this.current();
        this.layout({ x, y, width, height });
    }

    /* Cadre validé : l'aperçu montre l'image cadrée, qui part avec le formulaire. */
    apply() {
        const [x, y, width, height] = this.current();
        const { w, h } = this.size;
        const canvas = document.createElement('canvas');
        canvas.width = 800;
        canvas.height = Math.round(800 / this.aspectValue);
        canvas.getContext('2d').drawImage(this.imageTarget, x * w, y * h, width * w, height * h, 0, 0, canvas.width, canvas.height);
        const preview = document.createElement('img');
        preview.alt = '';
        preview.className = this.imageClassValue;
        preview.src = canvas.toDataURL('image/webp', 0.85);
        this.previewTarget.replaceChildren(preview);
        this.panelTarget.hidden = true;
        this.element.classList.add('picked');
        this.say(this.fileUrl ? 'Image prête : enregistrez pour la publier.' : 'Cadrage prêt : enregistrez pour le publier.');
    }

    /* Annuler : nouveau fichier abandonné et cadre d'origine rétabli. */
    cancel() {
        this.panelTarget.hidden = true;
        if (this.fileUrl) {
            this.inputTarget.value = '';
            this.revoke();
            this.adjustTarget.hidden = !this.sourceValue;
        }
        this.cropTarget.value = '';
        this.previewTarget.replaceChildren(...this.originalPreview.map((node) => node.cloneNode(true)));
        this.element.classList.remove('picked');
        this.noteTarget.hidden = true;
    }

    parse(value) {
        const parts = (value || '').split(',').map(Number);
        if (parts.length !== 4 || parts.some((v) => !Number.isFinite(v))) return null;
        const [x, y, width, height] = parts;
        return { x, y, width, height };
    }

    say(message) {
        this.noteTarget.textContent = message;
        this.noteTarget.hidden = false;
    }

    revoke() {
        if (this.fileUrl) URL.revokeObjectURL(this.fileUrl);
        this.fileUrl = null;
    }
}
