import { Controller } from '@hotwired/stimulus';
import { SOUND_EVENT, playAlone, setSound, soundOn } from '../lib/sound.js';

/*
 * Vidéo dans un fil, comme sur Instagram : l'affiche d'abord, lecture dès que la vidéo est bien
 * visible (une seule à la fois), bouton son en bas à gauche ; un clic l'ouvre dans la visionneuse (lightbox_controller).
 *
 * Le lecteur (<video>) n'existe que pendant que la vidéo est à l'écran : préparé quand elle arrive (25 % visible, le
 * téléchargement commence), lancé quand elle est bien visible (60 %), supprimé dès qu'elle quitte l'écran ou que le
 * carrousel passe à un autre média (l'affiche, une simple image, reste en place). Safari sur iPhone laisse noires les
 * vidéos au-delà de quelques lecteurs dans la page, et un fil en comptait plus d'une centaine.
 *
 * Pas d'écran noir pendant le chargement : un lecteur se peint en noir tant qu'il n'a pas d'image. Il reste donc
 * invisible au-dessus de l'affiche, et n'apparaît (classe « ready ») qu'à sa première image réellement affichée.
 * Créé ici, dans la page, le lecteur a aussi les règles de la page pour la lecture automatique muette (une vidéo
 * arrivée par une navigation Turbo garde sinon celles d'un document à part, et reste noire sur iPhone).
 *
 * Le choix du son vaut pour toutes les vidéos et musiques (voir lib/sound.js). Dans une publication
 * qui a une musique, la vidéo reste muette : la musique remplace son son, comme sur Instagram.
 */
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

// Part visible de la vidéo : lecteur préparé (téléchargement), puis lancé. Marge pour l'arrondi des rapports mesurés.
const PREPARE = 0.25;
const PLAY = 0.6;
const reached = (ratio, threshold) => ratio >= threshold - 0.01;

/*
 * Arrête une vidéo et libère son décodeur : remise à l'état initial, sans rien retélécharger.
 * Safari (iPhone) garde sinon le décodeur d'une vidéo retirée de la page, et la suivante reste noire.
 */
export function release(video) {
    video.pause();
    video.removeAttribute('src');
    video.querySelectorAll('source').forEach((source) => source.remove());
    video.preload = 'none';
    video.load();
}

// Avant la mise en cache d'une page par Turbo : lecteurs supprimés (la copie gardée ne joue rien).
document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('.player video').forEach((video) => { video.closest('.player')?.classList.remove('ready'); release(video); video.remove(); });
});

export default class extends Controller {
    static targets = ['sound'];
    static values = { src: String, type: String, label: String };

    connect() {
        this.visible = false;
        this.observer = new IntersectionObserver(([entry]) => this.visibilityChanged(entry), { threshold: [0, PREPARE, PLAY] });
        this.observer.observe(this.element);
        this.onSound = (event) => this.applySound(event.detail.on);
        window.addEventListener(SOUND_EVENT, this.onSound);
        this.render();
    }

    disconnect() {
        this.visible = false;
        this.observer.disconnect();
        window.removeEventListener(SOUND_EVENT, this.onSound);
        this.destroy();
    }

    get video() {
        return this.element.querySelector('video');
    }

    // Hors de l'écran : lecteur supprimé ; qui arrive : préparé, en pause ; bien visible : lancé.
    visibilityChanged(entry) {
        const ratio = entry.isIntersecting && this.element.isConnected ? entry.intersectionRatio : 0;
        this.visible = reached(ratio, PLAY);
        if (!entry.isIntersecting || !this.element.isConnected) {
            this.destroy();
            this.render();
            return;
        }
        if (this.visible) {
            if (!this.blocked) this.play();
            return;
        }
        this.video?.pause();
        if (reached(ratio, PREPARE) && !this.video && !this.blocked) this.create();
    }

    /*
     * Pas de lecture automatique derrière un avertissement de contenu ou si l'utilisateur limite les animations, ni
     * dans la copie de la page en cache affichée par Turbo le temps du chargement (elle va être remplacée).
     */
    get blocked() {
        return reducedMotion.matches
            || this.element.closest('.media')?.querySelector('.cw-veil') !== null
            || document.documentElement.hasAttribute('data-turbo-preview');
    }

    // Publication avec musique : la vidéo reste muette.
    get silent() {
        return this.element.closest('.media')?.hasAttribute('data-music') ?? false;
    }

    create() {
        const video = document.createElement('video');
        video.muted = true;
        video.defaultMuted = true;
        video.playsInline = true;
        video.loop = true;
        video.preload = 'auto';
        video.setAttribute('muted', '');
        video.setAttribute('playsinline', '');
        // Pas d'attribut poster : l'affiche, en dessous, reste visible tant que le lecteur n'a pas d'image.
        if (this.labelValue) video.setAttribute('aria-label', this.labelValue);
        const source = document.createElement('source');
        source.src = this.srcValue;
        if (this.typeValue) source.type = this.typeValue;
        video.append(source);
        video.addEventListener('play', this.onPlay);
        this.soundTarget.before(video);
        this.revealOnFirstFrame(video);
        return video;
    }

    /*
     * Lecteur montré à sa première image réellement affichée : requestVideoFrameCallback (Safari, Chrome), seul signal
     * fiable sur iPhone, où « playing » arrive parfois avant l'image ; sinon, le temps de lecture qui avance (Firefox).
     */
    revealOnFirstFrame(video) {
        const show = () => { if (video.isConnected) this.element.classList.add('ready'); };
        if ('requestVideoFrameCallback' in video) {
            video.requestVideoFrameCallback(show);
            return;
        }
        const onTime = () => {
            if (video.currentTime <= 0) return;
            video.removeEventListener('timeupdate', onTime);
            show();
        };
        video.addEventListener('timeupdate', onTime);
    }

    destroy() {
        const video = this.video;
        if (!video) return;
        video.removeEventListener('play', this.onPlay);
        this.element.classList.remove('ready');
        release(video);
        video.remove();
    }

    play() {
        const video = this.video ?? this.create();
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
    onPlay = (event) => playAlone(event.target);

    toggleSound(event) {
        event.stopPropagation();
        setSound(this.video ? this.video.muted : !soundOn());
    }

    // Choix du son fait sur n'importe quelle vidéo : appliqué à celle-ci si elle joue.
    applySound(on) {
        const video = this.video;
        if (video) video.muted = this.silent || !on;
        if (on && this.visible && !this.blocked && (!video || video.paused)) this.play();
        this.render();
    }

    render() {
        const muted = this.video ? this.video.muted : (this.silent || !soundOn());
        this.element.classList.toggle('muted', muted);
        this.soundTarget.setAttribute('aria-pressed', String(!muted));
        this.soundTarget.setAttribute('aria-label', muted ? 'Activer le son' : 'Couper le son');
    }
}
