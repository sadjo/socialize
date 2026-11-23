import { Controller } from '@hotwired/stimulus';

/*
 * Bouton « Réduire le menu » / « Agrandir » du menu de gauche : bascule l'attribut data-menu de <html> (les styles en
 * dépendent) et mémorise le choix de cet appareil pour un an dans le cookie « menu », relu par le serveur au rendu de
 * chaque page. Menu réduit : chaque entrée reçoit son nom en title, affiché au survol quand seules les icônes restent.
 */
export default class extends Controller {
    connect() {
        this.syncTitles();
    }

    toggle() {
        const root = document.documentElement;
        const reduced = root.dataset.menu !== 'reduit';
        root.dataset.menu = reduced ? 'reduit' : 'large';
        document.cookie = `menu=${reduced ? 'reduit' : 'large'}; path=/; max-age=31536000; SameSite=Lax`;
        this.syncTitles();
    }

    syncTitles() {
        const reduced = document.documentElement.dataset.menu === 'reduit';
        for (const entry of this.element.closest('nav')?.querySelectorAll('.nav') ?? []) {
            const label = [...entry.querySelectorAll('.lbl')].find((lbl) => getComputedStyle(lbl).display !== 'none');
            if (reduced && label) entry.title = label.textContent.trim();
            else entry.removeAttribute('title');
        }
    }
}
