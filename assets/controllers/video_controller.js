import { Controller } from '@hotwired/stimulus';
import { SOUND_EVENT, playAlone, setSound, soundOn } from '../lib/sound.js';

/*
 * Vidéo dans un fil, comme sur Instagram : l'affiche d'abord, lecture dès que la vidéo est bien
 * visible (une seule à la fois), bouton son en bas à gauche ; un clic l'ouvre dans la visionneuse (lightbox_controller).
 *
 * Le choix du son vaut pour toutes les vidéos et musiques (voir lib/sound.js). Dans une publication
 * qui a une musique, la vidéo reste muette : la musique remplace son son, comme sur Instagram.
 */
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

export default class extends Controller {
    static targets = ['video', 'sound'];

    connect() {
        this.visible = false;
        this.observer = new IntersectionObserver(([entry]) => this.visibilityChanged(entry), { threshold: 0.6 });
        this.observer.observe(this.videoTarget);
        this.onSound = (event) => this.applySound(event.detail.on);
        window.addEventListener(SOUND_EVENT, this.onSound);
        this.videoTarget.addEventListener('play', this.onPlay);
        this.render();
    }

    disconnect() {
        this.observer.disconnect();
        window.removeEventListener(SOUND_EVENT, this.onSound);
        this.videoTarget.removeEventListener('play', this.onPlay);
        this.videoTarget.pause();
    }

    visibilityChanged(entry) {
        this.visible = entry.isIntersecting;
        if (!this.visible) {
            this.videoTarget.pause();
            return;
        }
        // Pas de lecture automatique derrière un avertissement de contenu ou si l'utilisateur limite les animations.
        if (reducedMotion.matches || this.element.closest('.media')?.querySelector('.cw-veil')) return;
        this.play();
    }

    // Publication avec musique : la vidéo reste muette.
    get silent() {
        return this.element.closest('.media')?.hasAttribute('data-music') ?? false;
    }

    play() {
        const video = this.videoTarget;
        video.muted = this.silent || !soundOn();
        this.render();
        video.play().catch(() => {
            // Le navigateur refuse le son sans geste de l'utilisateur sur cette vidéo : lecture muette.
            video.muted = true;
            this.render();
            video.play().catch(() => {});
        });
    }

    // Une seule publication à la fois : les vidéos et musiques des autres se mettent en pause.
    onPlay = () => playAlone(this.videoTarget);

    toggleSound(event) {
        event.stopPropagation();
        setSound(this.videoTarget.muted);
    }

    // Choix du son fait sur n'importe quelle vidéo : appliqué à celle-ci si elle joue.
    applySound(on) {
        const video = this.videoTarget;
        video.muted = this.silent || !on;
        if (on && this.visible && video.paused) this.play();
        this.render();
    }

    render() {
        const muted = this.videoTarget.muted;
        this.element.classList.toggle('muted', muted);
        this.soundTarget.setAttribute('aria-pressed', String(!muted));
        this.soundTarget.setAttribute('aria-label', muted ? 'Activer le son' : 'Couper le son');
    }
}
