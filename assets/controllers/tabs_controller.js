import { Controller } from '@hotwired/stimulus';

/*
 * Onglets défilants (réglages, administration) quand ils ne tiennent pas sur la largeur :
 * - l'onglet ouvert est amené au milieu de la bande, sans faire défiler la page ;
 * - des flèches font défiler la bande (au doigt, elle glisse ; à la souris, sa barre de défilement est masquée) ;
 * - la molette verticale la fait défiler horizontalement, puis laisse défiler la page une fois au bout.
 */
export default class extends Controller {
    static targets = ['strip', 'prev', 'next'];

    connect() {
        const strip = this.stripTarget;
        const current = strip.querySelector('.on');
        if (current && strip.scrollWidth > strip.clientWidth) {
            strip.scrollLeft = current.offsetLeft - (strip.clientWidth - current.offsetWidth) / 2;
        }
        this.resizeObserver = new ResizeObserver(() => this.update());
        this.resizeObserver.observe(strip);
        this.update();
    }

    disconnect() {
        this.resizeObserver?.disconnect();
    }

    previous() {
        this.scroll(-1);
    }

    next() {
        this.scroll(1);
    }

    wheel(event) {
        const strip = this.stripTarget;
        // Défilement horizontal (pavé tactile) : laissé au navigateur.
        if (Math.abs(event.deltaX) >= Math.abs(event.deltaY) || strip.scrollWidth <= strip.clientWidth) return;
        const max = strip.scrollWidth - strip.clientWidth;
        if ((event.deltaY < 0 && strip.scrollLeft <= 0) || (event.deltaY > 0 && strip.scrollLeft >= max - 1)) return;
        event.preventDefault();
        strip.scrollLeft += event.deltaY;
    }

    update() {
        const strip = this.stripTarget;
        const max = strip.scrollWidth - strip.clientWidth;
        this.prevTarget.hidden = strip.scrollLeft <= 1;
        this.nextTarget.hidden = max <= 1 || strip.scrollLeft >= max - 1;
    }

    scroll(direction) {
        const strip = this.stripTarget;
        strip.scrollBy({ left: direction * strip.clientWidth * 0.7, behavior: 'smooth' });
    }
}
