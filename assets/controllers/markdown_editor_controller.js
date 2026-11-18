/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/* Barre d'édition Markdown : gras, italique, lien, listes, image envoyée au serveur, et aperçu rendu par le serveur
   (le même rendu que sur le site). Sans JavaScript, la zone de texte reste utilisable telle quelle. */
export default class extends Controller {
    static targets = ['bar', 'tools', 'input', 'output', 'file', 'status', 'writeTab', 'previewTab'];
    static values = { imageUrl: String, previewUrl: String, token: String };

    connect() {
        this.barTarget.hidden = false;
    }

    bold() {
        this.wrap('**', '**', 'texte en gras');
    }

    italic() {
        this.wrap('*', '*', 'texte en italique');
    }

    link() {
        const url = window.prompt('Adresse du lien', 'https://');
        if (!url || url === 'https://') return;
        const { selectionStart: start, selectionEnd: end, value } = this.inputTarget;
        const label = value.slice(start, end) || 'texte du lien';
        this.replace(start, end, `[${label}](${url.trim()})`, start + 1, start + 1 + label.length);
    }

    bullets() {
        this.prefixLines(() => '- ', /^[-*+] /);
    }

    numbers() {
        this.prefixLines((index) => `${index + 1}. `, /^\d+\. /);
    }

    image() {
        this.fileTarget.click();
    }

    async upload() {
        const file = this.fileTarget.files?.[0];
        this.fileTarget.value = '';
        if (!file) return;
        const placeholder = `![Envoi de ${file.name}…]()`;
        this.insertBlock(placeholder);
        this.say('Envoi de l’image…');
        try {
            const body = new FormData();
            body.append('image', file);
            body.append('_token', this.tokenValue);
            const response = await fetch(this.imageUrlValue, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.url) throw new Error(data.error || 'L’image n’a pas pu être envoyée.');
            const alt = file.name.replace(/\.[^.]+$/, '').replace(/[[\]]/g, '');
            this.swap(placeholder, `![${alt}](${data.url})`);
            this.say('Image ajoutée. Modifiez le texte entre crochets pour la décrire.');
        } catch (error) {
            this.swap(placeholder, '');
            this.say(error.message);
        }
    }

    shortcut(event) {
        if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
        const action = { b: 'bold', i: 'italic', k: 'link' }[event.key.toLowerCase()];
        if (!action) return;
        event.preventDefault();
        this[action]();
    }

    write() {
        this.mode(false);
        this.inputTarget.focus();
    }

    async preview() {
        this.mode(true);
        this.outputTarget.textContent = 'Aperçu en cours…';
        try {
            const body = new FormData();
            body.append('body', this.inputTarget.value);
            body.append('_token', this.tokenValue);
            const response = await fetch(this.previewUrlValue, { method: 'POST', body });
            if (!response.ok) throw new Error();
            // HTML produit par le serveur à partir du Markdown, HTML brut retiré.
            this.outputTarget.innerHTML = (await response.text()) || '<p class="small">Rien à afficher.</p>';
        } catch {
            this.outputTarget.textContent = 'L’aperçu n’a pas pu être affiché.';
        }
    }

    mode(previewing) {
        this.inputTarget.hidden = previewing;
        this.outputTarget.hidden = !previewing;
        this.toolsTarget.hidden = previewing;
        this.writeTabTarget.classList.toggle('on', !previewing);
        this.previewTabTarget.classList.toggle('on', previewing);
        this.writeTabTarget.setAttribute('aria-selected', String(!previewing));
        this.previewTabTarget.setAttribute('aria-selected', String(previewing));
    }

    wrap(before, after, placeholder) {
        const { selectionStart: start, selectionEnd: end, value } = this.inputTarget;
        const text = value.slice(start, end) || placeholder;
        this.replace(start, end, before + text + after, start + before.length, start + before.length + text.length);
    }

    /* Ajoute (ou retire, si toutes l'ont déjà) une marque de liste au début des lignes sélectionnées. */
    prefixLines(prefix, pattern) {
        const { selectionStart, selectionEnd, value } = this.inputTarget;
        const start = value.lastIndexOf('\n', selectionStart - 1) + 1;
        let end = value.indexOf('\n', selectionEnd);
        if (end === -1) end = value.length;
        const lines = value.slice(start, end).split('\n');
        const all = lines.every((line) => pattern.test(line));
        const text = lines.map((line, index) => (all ? line.replace(pattern, '') : prefix(index) + line.replace(/^([-*+]|\d+\.) /, ''))).join('\n');
        this.replace(start, end, text, start, start + text.length);
    }

    /* Insère sur sa propre ligne, à l'endroit du curseur. */
    insertBlock(text) {
        const { selectionEnd: at, value } = this.inputTarget;
        const before = at > 0 && value[at - 1] !== '\n' ? '\n' : '';
        const after = value[at] !== undefined && value[at] !== '\n' ? '\n' : '';
        const insert = before + text + after;
        this.replace(at, at, insert, at + insert.length, at + insert.length);
    }

    swap(search, replacement) {
        const index = this.inputTarget.value.indexOf(search);
        if (index === -1) return;
        let end = index + search.length;
        if (replacement === '' && this.inputTarget.value[end] === '\n') end += 1;
        this.replace(index, end, replacement, index + replacement.length, index + replacement.length);
    }

    replace(start, end, text, selectStart, selectEnd) {
        const input = this.inputTarget;
        input.focus();
        input.setRangeText(text, start, end, 'end');
        input.setSelectionRange(selectStart, selectEnd);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    say(message) {
        this.statusTarget.textContent = message;
        this.statusTarget.hidden = false;
    }
}
