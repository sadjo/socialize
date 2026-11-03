import { Controller } from '@hotwired/stimulus';

/* « J'aime » par double tap sur les médias, avec un cœur animé. */
export default class extends Controller {
    static targets = ['form', 'source'];

    doubleTap(event) {
        if (event.target.closest('button, a, video') || !this.hasFormTarget) return;
        const media = event.currentTarget;
        const burst = document.createElement('div');
        burst.className = 'burst';
        burst.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><use href="#i-heart"/></svg>';
        media.appendChild(burst);
        setTimeout(() => burst.remove(), 950);

        if (this.formTarget.querySelector('.act.liked')) return;
        this.sourceTarget.value = 'double-tap';
        this.formTarget.requestSubmit();
    }
}
