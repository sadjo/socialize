import { Controller } from '@hotwired/stimulus';

const KEY = 'socialize-theme';

/* Bascule entre thème sombre et thème clair, mémorisée dans le navigateur. */
export default class extends Controller {
    static targets = ['label'];

    connect() { this.render(); }

    toggle() {
        const next = this.isDark() ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem(KEY, next); } catch {}
        this.render();
    }

    isDark() {
        const theme = document.documentElement.dataset.theme;
        return theme ? theme === 'dark' : !window.matchMedia('(prefers-color-scheme: light)').matches;
    }

    render() {
        if (this.hasLabelTarget) this.labelTarget.textContent = this.isDark() ? 'Thème clair' : 'Thème sombre';
        const use = this.element.querySelector('use');
        if (use) use.setAttribute('href', this.isDark() ? '#i-sun' : '#i-moon');
    }
}
