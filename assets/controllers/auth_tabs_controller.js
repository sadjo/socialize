import { Controller } from '@hotwired/stimulus';

/*
 * Onglets de l'écran de connexion (Mastodon, socialize.example). Le serveur ouvre déjà le bon onglet ; sans onglet imposé
 * (forced : retour après une erreur), le dernier choisi sur cet appareil est rouvert. Flèches gauche et droite au clavier.
 */
const STORAGE_KEY = 'socialize.login-tab';

export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = { forced: String };

    connect() {
        if (this.forcedValue) return;
        let saved = null;
        try {
            saved = window.localStorage.getItem(STORAGE_KEY);
        } catch {
            // Stockage indisponible (navigation privée…) : l'onglet du serveur reste ouvert.
        }
        if (saved && this.tabTargets.some((tab) => tab.dataset.tab === saved)) this.show(saved, false);
    }

    select(event) {
        this.show(event.currentTarget.dataset.tab, true);
        try {
            window.localStorage.setItem(STORAGE_KEY, event.currentTarget.dataset.tab);
        } catch {
            // Rien à garder.
        }
    }

    key(event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        const index = this.tabTargets.indexOf(event.currentTarget);
        const next = this.tabTargets[(index + (event.key === 'ArrowRight' ? 1 : -1) + this.tabTargets.length) % this.tabTargets.length];
        next.click();
        next.focus();
    }

    show(name, focus) {
        this.tabTargets.forEach((tab) => {
            const on = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
        });
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.tab !== name;
            if (!panel.hidden && focus) panel.querySelector('input:not([type="hidden"]), select')?.focus({ preventScroll: true });
        });
    }
}
