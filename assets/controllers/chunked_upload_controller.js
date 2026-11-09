import { Controller } from '@hotwired/stimulus';

const CHUNK_SIZE = 50 * 1024 * 1024;
const RETRIES = 6;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/*
 * Envoi d'une archive (une ou plusieurs parties) par morceaux de 50 Mo.
 * Après une coupure, choisir à nouveau les mêmes fichiers reprend l'envoi là où il s'est arrêté :
 * le serveur indique combien d'octets il a déjà reçus pour chaque partie.
 */
export default class extends Controller {
    static targets = ['input', 'zone', 'progress', 'bar', 'status'];
    static values = { beginUrl: String, token: String };

    choose() {
        this.upload([...this.inputTarget.files]);
    }

    dragover(event) {
        event.preventDefault();
        this.zoneTarget.classList.add('over');
    }

    dragleave() {
        this.zoneTarget.classList.remove('over');
    }

    drop(event) {
        event.preventDefault();
        this.zoneTarget.classList.remove('over');
        this.upload([...event.dataTransfer.files]);
    }

    async upload(files) {
        files = files.filter((file) => file.name.toLowerCase().endsWith('.zip'));
        if (files.length === 0 || this.busy) {
            if (!this.busy) this.status('Choisissez l’archive .zip téléchargée depuis Instagram.', true);
            return;
        }
        this.busy = true;
        this.inputTarget.disabled = true;
        this.progressTarget.hidden = false;
        this.status('Préparation de l’envoi…');

        try {
            const start = await this.request(this.beginUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ parts: files.map((file) => ({ name: file.name, size: file.size })) }),
            });
            const total = start.parts.reduce((sum, part) => sum + part.size, 0);
            let done = start.parts.reduce((sum, part) => sum + part.received, 0);
            this.progress(done, total);

            for (const [index, part] of start.parts.entries()) {
                const file = files.find((f) => f.name === part.name && f.size === part.size) ?? files[index];
                let offset = part.received;
                while (offset < part.size) {
                    const result = await this.sendChunk(part.url, offset, file.slice(offset, Math.min(offset + CHUNK_SIZE, part.size)));
                    done += result.received - offset;
                    offset = result.received;
                    this.progress(done, total);
                }
            }
            this.status('Archive reçue. Analyse en cours…');
            window.location.assign(start.show_url);
        } catch (error) {
            this.status(error.message, true);
            this.inputTarget.disabled = false;
            this.inputTarget.value = '';
            this.busy = false;
        }
    }

    async sendChunk(url, offset, blob) {
        for (let attempt = 0; attempt < RETRIES; attempt++) {
            try {
                const response = await fetch(`${url}?debut=${offset}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': this.tokenValue },
                    body: blob,
                });
                const data = await response.json().catch(() => ({}));
                // 409 : le serveur en a reçu plus (ou moins) que prévu ; on repart de sa position.
                if (response.ok || response.status === 409) return data;
                if (response.status < 500) throw new Error(data.error ?? 'Envoi refusé par le serveur.');
            } catch (error) {
                if (!(error instanceof TypeError)) throw error;
            }
            this.status('Connexion perdue, nouvelle tentative…');
            await sleep(1000 * 2 ** attempt);
        }
        throw new Error('L’envoi s’est interrompu. Choisissez à nouveau le même fichier pour le reprendre là où il s’est arrêté.');
    }

    async request(url, options) {
        const response = await fetch(url, { ...options, headers: { ...options.headers, 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const violations = data.violations?.map((v) => v.title ?? v.message).join(' ');
            throw new Error(data.error ?? (violations || 'Envoi refusé par le serveur.'));
        }
        return data;
    }

    progress(done, total) {
        const percent = total > 0 ? Math.floor((done / total) * 100) : 0;
        this.barTarget.firstElementChild.style.width = `${percent}%`;
        this.barTarget.setAttribute('aria-valuenow', percent);
        const mb = (bytes) => Math.round(bytes / 1048576).toLocaleString('fr-FR');
        this.status(`${percent} % · ${mb(done)} Mo sur ${mb(total)} Mo envoyés. Gardez cette page ouverte.`);
    }

    status(message, error = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.classList.toggle('err', error);
    }
}
