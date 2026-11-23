import { Controller } from '@hotwired/stimulus';

/*
 * Bouton du logo du menu de gauche (« Réduire le menu » à droite du logo, « Agrandir le menu » dessous) : bascule
 * l'attribut data-menu de <html> (les styles en dépendent) et mémorise le choix de cet appareil pour un an dans le
 * cookie « menu », relu par le serveur au rendu de chaque page. Menu réduit : chaque entrée reçoit son nom en title,
 * affiché au survol quand seules les icônes restent.
 */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.sync();
    }

    toggle() {
        const root = document.documentElement;
        const reduced = root.dataset.menu !== 'reduit';
        root.dataset.menu = reduced ? 'reduit' : 'large';
        document.cookie = `menu=${reduced ? 'reduit' : 'large'}; path=/; max-age=31536000; SameSite=Lax`;
        this.sync();
    }

    sync() {
        const reduced = document.documentElement.dataset.menu === 'reduit';
        const label = reduced ? 'Agrandir le menu' : 'Réduire le menu';
        this.buttonTarget.setAttribute('aria-label', label);
        this.buttonTarget.title = label;
        for (const entry of this.element.closest('nav')?.querySelectorAll('.nav') ?? []) {
            const name = entry.querySelector('.lbl')?.textContent.trim();
            if (reduced && name) entry.title = name;
            else entry.removeAttribute('title');
        }
    }
}
