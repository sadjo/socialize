import { Controller } from '@hotwired/stimulus';
import { toast } from '../lib/toast.js';

/*
 * Feuille de partage d'une publication : e-mail, copie du lien, Facebook, Instagram,
 * et le partage du système (mobile) quand le navigateur le propose.
 */
export default class extends Controller {
    static targets = ['native', 'mail', 'facebook'];

    connect() {
        this.shared = null;
        this.nativeTarget.hidden = !navigator.share;
    }

    open(event) {
        this.shared = event.detail;
        const { url, title } = this.shared;
        this.mailTarget.href = `mailto:?subject=${encodeURIComponent(title)}&body=${encodeURIComponent(`${title}\n${url}`)}`;
        this.facebookTarget.href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`;
        this.element.showModal();
    }

    close() {
        this.element.close();
    }

    // Clic sur le fond de la boîte de dialogue : fermeture.
    backdrop(event) {
        if (event.target === this.element) this.close();
    }

    async native() {
        const { url, title, text } = this.shared;
        try {
            await navigator.share({ url, title, text });
            this.close();
        } catch { /* partage annulé */ }
    }

    async copy() {
        await this.copyLink();
        this.close();
        toast('Lien copié !');
    }

    // Instagram ne propose pas de lien de partage pour le web : on transmet le média lui-même
    // au partage du système (l'application Instagram y figure sur mobile), sinon on copie le lien.
    async instagram() {
        const file = await this.mediaFile().catch(() => null);
        if (file && navigator.canShare?.({ files: [file] })) {
            try {
                await navigator.share({ files: [file], text: this.shared.url });
                this.close();
            } catch { /* partage annulé */ }
            return;
        }
        await this.copyLink();
        this.close();
        toast('Lien copié : collez-le dans un message ou une story Instagram.');
    }

    async copyLink() {
        try {
            await navigator.clipboard.writeText(this.shared.url);
        } catch {
            window.prompt('Copiez ce lien :', this.shared.url);
        }
    }

    // Photo convertie en JPEG (Instagram n'accepte pas le WebP) ; vidéo transmise telle quelle.
    async mediaFile() {
        const { media, video } = this.shared;
        if (!media) return null;
        const blob = await (await fetch(media, { mode: 'cors' })).blob();
        if (video) return new File([blob], 'socialize.mp4', { type: blob.type || 'video/mp4' });
        const bitmap = await createImageBitmap(blob);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        canvas.getContext('2d').drawImage(bitmap, 0, 0);
        const jpeg = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
        return jpeg ? new File([jpeg], 'socialize.jpg', { type: 'image/jpeg' }) : null;
    }
}
