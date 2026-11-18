/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { toast } from '../lib/toast.js';

/*
 * « Partager vers Instagram » (tout compte Instagram, personnel compris) : les photos (JPEG) et vidéos de la
 * publication passent par le menu de partage du téléphone, où l'on choisit Instagram (publication ou story).
 * Instagram ne reçoit pas de texte par ce menu : la légende est copiée, à coller dans Instagram.
 *
 * Safari n'ouvre le menu de partage que juste après un toucher : les fichiers sont donc préparés dès l'ouverture du
 * menu « … » (ou au premier toucher), et le partage part au toucher suivant s'ils n'étaient pas encore prêts.
 * Sans partage de fichiers (la plupart des ordinateurs) : téléchargement des fichiers et copie de la légende.
 */
export default class extends Controller {
    static values = { files: Array, caption: String };

    connect() {
        this.files = null;
        this.loading = null;
        // Préparation dès l'ouverture du menu « … » qui contient le bouton.
        this.menu = this.element.closest('details');
        this.onToggle = () => { if (this.menu.open) this.prepare(); };
        this.menu?.addEventListener('toggle', this.onToggle);
    }

    disconnect() {
        this.menu?.removeEventListener('toggle', this.onToggle);
    }

    prepare() {
        this.loading ??= Promise.all(this.filesValue.map(async ({ url, name, type }) => {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(`${response.status}`);
            return new File([await response.blob()], name, { type });
        })).then((files) => { this.files = files; return files; }).catch((error) => { this.loading = null; throw error; });

        return this.loading;
    }

    get canShareFiles() {
        return typeof navigator.canShare === 'function' && this.files !== null && navigator.canShare({ files: this.files });
    }

    async share(event) {
        event.preventDefault();
        await this.copyCaption();
        if (this.files === null) {
            if (typeof navigator.canShare !== 'function') { this.fallback(); return; }
            toast('Préparation des fichiers…');
            try {
                await this.prepare();
            } catch {
                toast('Les fichiers n’ont pas pu être préparés. Réessayez.');
                return;
            }
            // Le toucher qui a lancé la préparation est consommé : Safari en demande un nouveau.
            toast('Prêt : touchez de nouveau « Partager vers Instagram ».');
            return;
        }
        if (!this.canShareFiles) { this.fallback(); return; }
        try {
            await navigator.share({ files: this.files });
        } catch (error) {
            if (error?.name !== 'AbortError') this.fallback();
        }
    }

    async copyCaption() {
        if (!this.captionValue) return;
        try {
            await navigator.clipboard.writeText(this.captionValue);
            toast('Légende copiée : collez-la dans Instagram.');
        } catch {
            // Presse-papiers refusé : la légende reste proposée dans la fenêtre de repli.
        }
    }

    // Ordinateur, ou navigateur sans partage de fichiers : téléchargement et marche à suivre.
    fallback() {
        const dialog = document.createElement('dialog');
        dialog.className = 'share-sheet share-ig';
        const links = this.filesValue.map(({ url, name }) => `<li><a class="lnk" href="${url}" download="${name}">${name}</a></li>`).join('');
        dialog.innerHTML = `
            <div class="sheet">
                <header><b>Partager vers Instagram</b><button type="button" class="ib" data-close aria-label="Fermer"><svg class="i sm" aria-hidden="true"><use href="#i-x"/></svg></button></header>
                <p class="small">Ce navigateur ne peut pas envoyer les fichiers directement à Instagram. Téléchargez-les, puis publiez-les depuis l’application Instagram (ou instagram.com) et collez la légende.</p>
                <ul class="share-ig-files">${links}</ul>
                <label class="field"><span class="lab">Légende</span><textarea class="inp" rows="4" readonly></textarea></label>
                <div class="navbtns"><span></span><button type="button" class="btn pri" data-copy>Copier la légende</button></div>
            </div>`;
        dialog.querySelector('textarea').value = this.captionValue;
        dialog.querySelector('[data-copy]').hidden = !this.captionValue;
        dialog.addEventListener('click', async (e) => {
            if (e.target === dialog || e.target.closest('[data-close]')) dialog.close();
            if (e.target.closest('[data-copy]')) await this.copyCaption();
        });
        dialog.addEventListener('close', () => dialog.remove());
        document.body.appendChild(dialog);
        dialog.showModal();
    }
}
