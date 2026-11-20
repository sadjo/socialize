/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Liens « Créer un compte » et « Mot de passe oublié » de l'onglet Mastodon : ils mènent au serveur choisi (création
 * de compte depuis Socialize pour le serveur de MASTODON_SIGNUP).
 */
export default class extends Controller {
    static targets = ['server', 'signup', 'forgot'];
    static values = { signupServer: String, signupUrl: String };

    update() {
        const domain = this.domain(this.serverTarget.value);
        if (!domain) {
            this.signupTarget.removeAttribute('href');
            this.forgotTarget.removeAttribute('href');
            return;
        }
        this.forgotTarget.href = `https://${domain}/auth/password/new`;
        if (domain === this.signupServerValue) {
            this.signupTarget.href = this.signupUrlValue;
            this.signupTarget.removeAttribute('target');
        } else {
            this.signupTarget.href = `https://${domain}/auth/sign_up`;
            this.signupTarget.target = '_blank';
            this.signupTarget.rel = 'noopener';
        }
    }

    // Même lecture que MastodonLogin::domainOf() : « mastodon.social », « https://mastodon.social/ », « @nom@serveur ».
    domain(input) {
        let value = input.trim().toLowerCase();
        const acct = value.match(/^@?[a-z0-9_.]+@([^@\s/]+)$/);
        if (acct) value = acct[1];
        try {
            const host = new URL(value.includes('://') ? value : `https://${value}`).hostname;
            return /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/.test(host) ? host : null;
        } catch {
            return null;
        }
    }
}
