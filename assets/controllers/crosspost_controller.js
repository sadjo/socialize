import { Controller } from '@hotwired/stimulus';

/*
 * Case « Publier aussi sur Instagram » : cochée d'avance selon les règles du compte (visibilités choisies dans
 * Réglages › Instagram), elle suit la visibilité tant qu'on ne l'a pas touchée. Un avertissement rappelle qu'une
 * publication non publique sera visible de tous sur Instagram.
 */
export default class extends Controller {
    static targets = ['box', 'warning'];
    static values = { defaults: Array, field: String };

    connect() {
        this.touched = false;
        this.form = this.element.closest('form');
        this.onChange = (event) => { if (event.target.name === this.fieldValue) this.update(); };
        this.form?.addEventListener('change', this.onChange);
        this.update();
    }

    disconnect() {
        this.form?.removeEventListener('change', this.onChange);
    }

    touch() {
        this.touched = true;
        this.update();
    }

    get visibility() {
        return this.form?.querySelector(`[name="${CSS.escape(this.fieldValue)}"]:checked`)?.value ?? 'public';
    }

    update() {
        const visibility = this.visibility;
        if (!this.touched) this.boxTarget.checked = this.defaultsValue.includes(visibility);
        this.warningTarget.hidden = !(this.boxTarget.checked && visibility !== 'public');
    }
}
