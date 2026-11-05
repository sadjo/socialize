import { Controller } from '@hotwired/stimulus';

/*
 * Vidéo dans le fil, comme sur Instagram : l'affiche d'abord, lecture sans le son dès que la vidéo
 * est bien visible, bouton en bas à gauche pour le son, et commandes du navigateur au clic.
 */
let soundOn = false; // Le son activé une fois reste activé pour les vidéos suivantes de la page.

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

export default class extends Controller {
    static targets = ['video', 'sound'];

    connect() {
        this.observer = new IntersectionObserver(([entry]) => this.visibilityChanged(entry), { threshold: 0.6 });
        this.observer.observe(this.videoTarget);
        this.render();
    }

    disconnect() {
        this.observer.disconnect();
        this.videoTarget.pause();
    }

    visibilityChanged(entry) {
        const video = this.videoTarget;
        if (!entry.isIntersecting) {
            video.pause();
            return;
        }
        // Pas de lecture automatique derrière un avertissement de contenu ou si l'utilisateur limite les animations.
        if (reducedMotion.matches || this.element.closest('.media')?.querySelector('.cw-veil')) return;
        video.muted = !soundOn;
        video.play().catch(() => {
            // Le navigateur refuse le son sans interaction : on relance en muet.
            video.muted = true;
            this.render();
            video.play().catch(() => {});
        });
        this.render();
    }

    toggleSound(event) {
        event.stopPropagation();
        const video = this.videoTarget;
        video.muted = !video.muted;
        soundOn = !video.muted;
        if (video.paused) video.play().catch(() => {});
    }

    showControls() {
        if (this.videoTarget.controls) return;
        this.videoTarget.controls = true;
        this.element.classList.add('with-controls');
    }

    // Le son a pu changer par le bouton ou par les commandes du navigateur.
    render() {
        const muted = this.videoTarget.muted;
        this.element.classList.toggle('muted', muted);
        this.soundTarget.setAttribute('aria-pressed', String(!muted));
        this.soundTarget.setAttribute('aria-label', muted ? 'Activer le son' : 'Couper le son');
    }

    volumeChanged() {
        if (this.videoTarget.controls) soundOn = !this.videoTarget.muted;
        this.render();
    }
}
