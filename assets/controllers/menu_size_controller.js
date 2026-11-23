import { Controller } from '@hotwired/stimulus';

/*
 * Bouton « Réduire le menu » / « Agrandir » du menu de gauche : bascule l'attribut data-menu de <html> (les styles en
 * dépendent) et mémorise le choix de cet appareil pour un an dans le cookie « menu », relu par le serveur au rendu de
 * chaque page.
 */
export default class extends Controller {
    toggle() {
        const root = document.documentElement;
        const reduced = root.dataset.menu !== 'reduit';
        root.dataset.menu = reduced ? 'reduit' : 'large';
        document.cookie = `menu=${reduced ? 'reduit' : 'large'}; path=/; max-age=31536000; SameSite=Lax`;
    }
}
