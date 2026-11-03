import { Controller } from '@hotwired/stimulus';

/* Carrousel des médias d'une publication : boutons, points et balayage tactile. */
export default class extends Controller {
    static targets = ['track', 'dot', 'counter'];
    static values = { count: Number, index: { type: Number, default: 0 } };

    connect() {
        this.startX = null;
        this.element.addEventListener('touchstart', this.onTouchStart, { passive: true });
        this.element.addEventListener('touchend', this.onTouchEnd, { passive: true });
    }

    disconnect() {
        this.element.removeEventListener('touchstart', this.onTouchStart);
        this.element.removeEventListener('touchend', this.onTouchEnd);
    }

    prev() { this.go(this.indexValue - 1); }
    next() { this.go(this.indexValue + 1); }

    go(index) {
        this.indexValue = Math.max(0, Math.min(this.countValue - 1, index));
    }

    indexValueChanged(index) {
        if (!this.hasTrackTarget) return;
        this.trackTarget.style.transform = `translateX(-${index * 100}%)`;
        this.dotTargets.forEach((dot, i) => dot.classList.toggle('on', i === index));
        if (this.hasCounterTarget) this.counterTarget.textContent = `${index + 1}/${this.countValue}`;
        this.element.querySelectorAll('video').forEach((video) => video.pause());
    }

    onTouchStart = (event) => { this.startX = event.changedTouches[0].clientX; };

    onTouchEnd = (event) => {
        if (this.startX === null) return;
        const delta = event.changedTouches[0].clientX - this.startX;
        this.startX = null;
        if (Math.abs(delta) > 40) delta < 0 ? this.next() : this.prev();
    };
}
