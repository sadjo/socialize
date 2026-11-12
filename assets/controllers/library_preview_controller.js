import { Controller } from '@hotwired/stimulus';
import { MediaViewer, tileEntry } from '../lib/media_viewer.js';

/*
 * Aperçu d'une photothèque (Immich, Synology Photos) à la création d'une publication ou d'une story :
 * la loupe d'une vignette l'ouvre en grand (toucher la vignette elle-même la choisit) ; on passe à la précédente
 * ou à la suivante, et « Choisir » l'ajoute à la publication, ou l'en retire, comme un toucher sur sa vignette.
 *
 * Chaque vignette (cible « tile ») décrit son aperçu par ses attributs data-preview-* (voir tileEntry).
 * La liste suit les pages chargées au défilement : la vignette affichée est gardée visible dans la liste,
 * ce qui fait charger la suite en arrivant au bout.
 */
export default class extends Controller {
    static targets = ['tile'];

    connect() {
        // Nouvelle page chargée pendant l'aperçu : la flèche « suivante » réapparaît.
        this.onFrameLoad = () => this.viewer?.renderNav();
        this.element.addEventListener('turbo:frame-load', this.onFrameLoad);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-load', this.onFrameLoad);
        this.viewer?.close();
    }

    open({ currentTarget }) {
        const index = this.tileTargets.indexOf(currentTarget.closest('.imm-tile'));
        if (index < 0) return;
        this.viewer = new MediaViewer({
            entries: () => this.tileTargets.map(tileEntry),
            index,
            select: {
                chosen: (i) => this.pick(i)?.getAttribute('aria-pressed') === 'true',
                // Même effet qu'un toucher sur la vignette (contrôleur compose) : la sélection reste unique.
                toggle: (i) => {
                    const pick = this.pick(i);
                    if (!pick) return '';
                    const before = pick.getAttribute('aria-pressed') === 'true';
                    pick.click();
                    const after = pick.getAttribute('aria-pressed') === 'true';
                    return !before && !after ? 'Le nombre maximal de médias est atteint : retirez-en un pour en choisir un autre.' : '';
                },
            },
            onShow: (i) => this.reveal(this.tileTargets[i]),
            onClose: (i) => {
                this.viewer = null;
                this.tileTargets[i]?.querySelector('.imm-zoom')?.focus({ preventScroll: true });
            },
        });
        this.viewer.open();
    }

    pick(index) {
        return this.tileTargets[index]?.querySelector('.imm-pick');
    }

    // La vignette affichée reste visible dans la liste (et la suite de la liste se charge en arrivant au bout).
    reveal(tile) {
        const list = tile?.closest('.imm-scroll');
        if (!list) return;
        const t = tile.getBoundingClientRect();
        const l = list.getBoundingClientRect();
        if (t.top < l.top || t.bottom > l.bottom) list.scrollTop += t.top - l.top - (l.height - t.height) / 2;
    }
}
