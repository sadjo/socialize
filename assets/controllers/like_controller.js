import { Controller } from '@hotwired/stimulus';
import { DOUBLE_TAP_DELAY } from '../lib/double_tap.js';

/*
 * « J'aime » par double tap (ou double-clic) sur les médias, photos comme vidéos, avec un cœur animé.
 * Le double tap est reconnu à partir de deux clics rapprochés : Safari sur iPhone n'envoie pas toujours
 * « dblclick » au toucher.
 */
export default class extends Controller {
    static targets = ['form', 'source'];

    tap(event) {
        if (event.target.closest('button, a, .cw-veil') || !this.hasFormTarget) return;
        const now = event.timeStamp;
        if (this.lastTap !== undefined && now - this.lastTap < DOUBLE_TAP_DELAY) {
            this.lastTap = undefined;
            this.doubleTap(event);
            return;
        }
        this.lastTap = now;
    }

    doubleTap(event) {
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
