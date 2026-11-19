import { Controller } from '@hotwired/stimulus';
import { release } from './video_controller.js';

/*
 * Carrousel des médias d'une publication : boutons (masqués aux extrémités), points cliquables et balayage tactile.
 *
 * Mémoire : le fil s'allonge sans fin. Safari sur iPhone cesse d'afficher les images (cadres gris) quand trop de photos
 * décodées restent dans la page. Seules la photo affichée et ses voisines sont chargées (les autres attendent dans
 * data-src / data-srcset), et une publication éloignée de l'écran (plus de deux écrans) libère ses images et ses
 * vidéos (affiche comprise), rechargées à son approche : la vidéo retrouve son affiche et son préchargement, et sa
 * source est resélectionnée (sinon WebKit la laisse noire au retour).
 */
const FAR = '200% 0px';

export default class extends Controller {
    static targets = ['track', 'dot', 'counter', 'prev', 'next'];
    static values = { count: Number, index: { type: Number, default: 0 } };

    connect() {
        this.startX = null;
        this.element.addEventListener('touchstart', this.onTouchStart, { passive: true });
        this.element.addEventListener('touchend', this.onTouchEnd, { passive: true });
        this.observer = new IntersectionObserver(([entry]) => (entry.isIntersecting ? this.load() : this.unload()), { rootMargin: FAR });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.element.removeEventListener('touchstart', this.onTouchStart);
        this.element.removeEventListener('touchend', this.onTouchEnd);
        this.observer.disconnect();
    }

    prev() { this.go(this.indexValue - 1); }
    // Point du carrousel : va au média correspondant.
    show({ params: { index } }) { this.go(Number(index)); }
    next() { this.go(this.indexValue + 1); }

    go(index) {
        this.indexValue = Math.max(0, Math.min(this.countValue - 1, index));
    }

    indexValueChanged(index) {
        if (!this.hasTrackTarget) return;
        this.load();
        // Premier média : pas de transformation (une couche graphique de moins, que WebKit repeint parfois mal).
        this.trackTarget.style.transform = index > 0 ? `translateX(-${index * 100}%)` : '';
        this.dotTargets.forEach((dot, i) => {
            dot.classList.toggle('on', i === index);
            if (i === index) dot.setAttribute('aria-current', 'true'); else dot.removeAttribute('aria-current');
        });
        if (this.hasCounterTarget) this.counterTarget.textContent = `${index + 1}/${this.countValue}`;
        if (this.hasPrevTarget) this.prevTarget.hidden = index <= 0;
        if (this.hasNextTarget) this.nextTarget.hidden = index >= this.countValue - 1;
        this.element.querySelectorAll('video').forEach((video) => video.pause());
    }

    // Images du média affiché et de ses voisins (prêts pour le balayage).
    load() {
        if (!this.hasTrackTarget) return;
        const slides = this.trackTarget.children;
        [this.indexValue - 1, this.indexValue, this.indexValue + 1].forEach((i) => {
            const img = slides[i]?.querySelector('img');
            if (!img) return;
            if (img.dataset.srcset) { img.srcset = img.dataset.srcset; delete img.dataset.srcset; }
            if (img.dataset.src) { img.src = img.dataset.src; delete img.dataset.src; }
        });
        this.element.querySelectorAll('.player video[data-preload]').forEach((video) => {
            if (video.dataset.poster) { video.setAttribute('poster', video.dataset.poster); delete video.dataset.poster; }
            video.preload = video.dataset.preload;
            delete video.dataset.preload;
            video.load();
        });
    }

    // Publication loin de l'écran : images rendues au navigateur (adresses gardées pour revenir), vidéos libérées.
    unload() {
        this.element.querySelectorAll('.slide img[src]').forEach((img) => {
            if (img.srcset) { img.dataset.srcset = img.srcset; img.removeAttribute('srcset'); }
            img.dataset.src = img.getAttribute('src');
            img.removeAttribute('src');
        });
        this.element.querySelectorAll('.player video:not([data-preload])').forEach((video) => {
            video.dataset.preload = video.preload;
            const poster = video.getAttribute('poster');
            if (poster) { video.dataset.poster = poster; video.removeAttribute('poster'); }
            release(video);
        });
    }

    onTouchStart = (event) => { this.startX = event.changedTouches[0].clientX; };

    onTouchEnd = (event) => {
        if (this.startX === null) return;
        const delta = event.changedTouches[0].clientX - this.startX;
        this.startX = null;
        if (Math.abs(delta) > 40) delta < 0 ? this.next() : this.prev();
    };
}
