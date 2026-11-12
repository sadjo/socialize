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

/*
 * Arrête une vidéo et libère son décodeur : remise à l'état initial (affiche), sans rien retélécharger.
 * Safari (iPhone) garde sinon le décodeur d'une vidéo retirée de la page, et la suivante reste noire.
 */
export function release(video) {
    video.pause();
    video.preload = 'none';
    video.load();
}

// Avant la mise en cache d'une page par Turbo : vidéos remises à zéro (la copie gardée ne joue rien).
document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('.player video').forEach(release);
});

export default class extends Controller {
    static targets = ['video', 'sound'];

    connect() {
        // Navigation Turbo : la page arrive d'un document à part (sans fenêtre), dont Safari sur iPhone garde les
        // règles pour la vidéo (lecture seulement après un geste, chargement jamais lancé : elle reste noire ou
        // figée). Une copie créée dans la page a les règles de la page : lecture automatique muette permise.
        // Première vidéo de la page (en haut du fil, d'une publication) : préchargée dès l'affichage, elle démarre
        // sans attendre son téléchargement. Les suivantes ne chargent rien avant d'être lues.
        const first = document.querySelector('.player video') === this.videoTarget;
        const fresh = this.videoTarget.cloneNode(true);
        fresh.muted = true;
        if (first) fresh.preload = 'auto';
        this.videoTarget.replaceWith(fresh);
        // Sélection de la source relancée (WebKit ne la fait pas seul ici).
        fresh.load();
        this.visible = false;
        this.observer = new IntersectionObserver(([entry]) => this.visibilityChanged(entry), { threshold: 0.6 });
        this.observer.observe(this.videoTarget);
        this.onSound = (event) => this.applySound(event.detail.on);
        window.addEventListener(SOUND_EVENT, this.onSound);
        this.videoTarget.addEventListener('play', this.onPlay);
        this.render();
    }

    disconnect() {
        this.visible = false;
        this.observer.disconnect();
        window.removeEventListener(SOUND_EVENT, this.onSound);
        this.videoTarget.removeEventListener('play', this.onPlay);
        release(this.videoTarget);
    }

    visibilityChanged(entry) {
        this.visible = entry.isIntersecting && this.videoTarget.isConnected;
        if (!this.visible) {
            this.videoTarget.pause();
            return;
        }
        // Pas de lecture automatique derrière un avertissement de contenu ou si l'utilisateur limite les animations.
        if (reducedMotion.matches || this.element.closest('.media')?.querySelector('.cw-veil')) return;
        // Copie de la page en cache affichée par Turbo le temps du chargement : elle va être remplacée, rien n'y joue
        // (une vidéo lancée là continuerait hors de la page et priverait la vraie du décodeur sur iPhone).
        if (document.documentElement.hasAttribute('data-turbo-preview')) return;
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
        video.play().catch((error) => {
            // Lecture interrompue (vidéo retirée de la page, mise en pause) : surtout pas de nouvelle tentative.
            if ('AbortError' === error?.name || !video.isConnected || !this.visible) return;
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
