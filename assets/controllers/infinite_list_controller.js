import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

/*
 * Liste chargée au défilement (fils, grilles) : une seule liste plate, suivie d'un repère de fin (list/_more.html.twig).
 * À l'approche du repère, la page suivante est demandée en flux Turbo : ses éléments sont insérés avant le repère, qui
 * est ensuite remplacé par celui de la page d'après (ou retiré en fin de liste).
 *
 * Remplace les cadres Turbo imbriqués page dans page : profondeur de la page constante, suite demandée avant d'arriver
 * en bas (pas d'attente ni de saut), une seule requête à la fois, et « Réessayer » si le réseau a lâché (fréquent sur
 * mobile), relancé seul au retour de la connexion.
 */
const AHEAD = '0px 0px 150% 0px';
const RETRY = 'Réessayer';

export default class extends Controller {
    static targets = ['more'];

    initialize() {
        this.observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) this.load();
        }, { rootMargin: AHEAD });
    }

    connect() {
        this.onOnline = () => { if (this.failed) this.load(); };
        window.addEventListener('online', this.onOnline);
    }

    disconnect() {
        window.removeEventListener('online', this.onOnline);
        this.observer.disconnect();
    }

    moreTargetConnected(element) { this.observer.observe(element); }

    moreTargetDisconnected(element) { this.observer.unobserve(element); }

    async load(event) {
        event?.preventDefault();
        if (this.busy || !this.hasMoreTarget) return;
        const more = this.moreTarget;
        const link = more.querySelector('a');
        if (!link) return;
        this.busy = true;
        this.failed = false;
        more.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(link.href, { headers: { Accept: 'text/vnd.turbo-stream.html' }, credentials: 'same-origin' });
            const contentType = response.headers.get('Content-Type') ?? '';
            if (!response.ok || !contentType.startsWith('text/vnd.turbo-stream.html')) throw new Error(`HTTP ${response.status}`);
            renderStreamMessage(await response.text());
        } catch {
            this.failed = true;
            if (more.isConnected) link.textContent = RETRY;
        } finally {
            this.busy = false;
            more.removeAttribute('aria-busy');
        }
    }
}
