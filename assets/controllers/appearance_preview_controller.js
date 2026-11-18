/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Réglages › Apparence : le thème et le filtre des médias cochés s'appliquent tout de suite à la page (attributs
 * data-theme et data-media-filter de <html>) pour voir le rendu. Sans enregistrement, les valeurs du compte
 * reviennent en quittant la page : Turbo garde <html> d'une page à l'autre, l'aperçu resterait sinon affiché.
 */
const ATTRIBUTES = ['theme', 'mediaFilter'];

export default class extends Controller {
    connect() {
        const { dataset } = document.documentElement;
        this.saved = Object.fromEntries(ATTRIBUTES.map((name) => [name, dataset[name]]));
        this.submitted = false;
    }

    // Paramètre « attr » de l'action : theme ou mediaFilter.
    preview(event) {
        const name = event.params.attr;
        if (ATTRIBUTES.includes(name)) document.documentElement.dataset[name] = event.target.value;
    }

    submit() {
        this.submitted = true;
    }

    disconnect() {
        if (this.submitted) return;
        for (const [name, value] of Object.entries(this.saved)) {
            if (undefined !== value) document.documentElement.dataset[name] = value;
        }
    }
}
