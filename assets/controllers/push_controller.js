/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Notifications sur cet appareil (Web Push) : demande l'accord de la personne, abonne l'appareil (service worker
 * /sw.js) et l'enregistre sur le serveur ; ou le désabonne. Sur iPhone et iPad, les notifications ne sont possibles
 * que dans l'application ajoutée à l'écran d'accueil.
 */
export default class extends Controller {
    static targets = ['status', 'enable', 'disable'];
    static values = { key: String, subscribeUrl: String, unsubscribeUrl: String, token: String };

    async connect() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            const ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            this.show(ios && !this.standalone()
                ? 'Sur iPhone et iPad : touchez Partager › « Sur l’écran d’accueil », ouvrez Socialize depuis son icône, puis revenez sur cette page.'
                : 'Ce navigateur ne permet pas les notifications.', null);
            return;
        }
        try {
            this.registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            const subscription = await this.registration.pushManager.getSubscription();
            this.render(subscription);
        } catch {
            this.show('Les notifications ne sont pas disponibles pour le moment.', null);
        }
    }

    async enable() {
        this.busy(true);
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                this.render(null);
                return;
            }
            const registration = this.registration ?? await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.decodeKey(this.keyValue) });
            await this.send(this.subscribeUrlValue, subscription.toJSON());
            this.render(subscription);
        } catch {
            this.show('L’activation a échoué. Réessayez.', false);
        } finally {
            this.busy(false);
        }
    }

    async disable() {
        this.busy(true);
        try {
            const subscription = await this.registration?.pushManager.getSubscription();
            if (subscription) {
                await this.send(this.unsubscribeUrlValue, { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
            }
            this.render(null);
        } finally {
            this.busy(false);
        }
    }

    render(subscription) {
        if (subscription) {
            this.show('Activées sur cet appareil.', true);
        } else if (Notification.permission === 'denied') {
            this.show('Notifications refusées sur cet appareil : autorisez-les pour Socialize dans les réglages du téléphone ou du navigateur.', null);
        } else {
            this.show('Désactivées sur cet appareil.', false);
        }
    }

    // enabled : true (désactiver possible), false (activer possible), null (aucun bouton).
    show(message, enabled) {
        this.statusTarget.textContent = message;
        this.enableTarget.hidden = enabled !== false;
        this.disableTarget.hidden = enabled !== true;
    }

    busy(on) {
        this.enableTarget.disabled = on;
        this.disableTarget.disabled = on;
    }

    standalone() {
        return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    }

    async send(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
            body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error('Envoi refusé');
    }

    decodeKey(base64url) {
        const padded = (base64url + '='.repeat((4 - base64url.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
    }
}
