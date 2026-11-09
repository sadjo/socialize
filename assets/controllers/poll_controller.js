import { Controller } from '@hotwired/stimulus';

/*
 * Recharge un cadre Turbo à intervalle régulier (progression d'un import).
 * Quand le cadre annonce la fin ([data-poll-done]), la page entière est rechargée.
 */
export default class extends Controller {
    static values = { interval: { type: Number, default: 2000 } };

    connect() {
        this.loaded = this.loaded.bind(this);
        this.element.addEventListener('turbo:frame-load', this.loaded);
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.element.removeEventListener('turbo:frame-load', this.loaded);
    }

    loaded() {
        if (this.element.querySelector('[data-poll-done]')) {
            window.location.reload();
            return;
        }
        this.schedule();
    }

    schedule() {
        clearTimeout(this.timer);
        if (this.element.querySelector('[data-poll-done]')) return;
        this.timer = setTimeout(() => this.element.reload(), this.intervalValue);
    }
}
