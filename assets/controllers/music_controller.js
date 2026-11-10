import { Controller } from '@hotwired/stimulus';
import { SOUND_EVENT, playAlone, setSound, soundOn } from '../lib/sound.js';

/*
 * Musique d'une publication, comme sur Instagram : elle se joue quand la publication est bien
 * visible, si le son est activé (les navigateurs refusent le son sans geste de l'utilisateur),
 * en boucle sur l'extrait choisi, et s'arrête quand la publication quitte l'écran.
 */
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

export default class extends Controller {
    static targets = ['audio', 'sound'];
    static values = { start: Number, excerpt: Number };

    connect() {
        this.visible = false;
        this.observer = new IntersectionObserver(([entry]) => this.visibilityChanged(entry), { threshold: 0.6 });
        this.observer.observe(this.element);
        this.onSound = (event) => this.applySound(event.detail.on);
        window.addEventListener(SOUND_EVENT, this.onSound);
        this.audioTarget.addEventListener('timeupdate', this.loop);
        this.audioTarget.addEventListener('ended', this.restart);
        this.audioTarget.addEventListener('play', this.onPlay);
        this.audioTarget.addEventListener('pause', this.onPause);
        this.render();
    }

    disconnect() {
        this.observer.disconnect();
        window.removeEventListener(SOUND_EVENT, this.onSound);
        this.audioTarget.removeEventListener('timeupdate', this.loop);
        this.audioTarget.removeEventListener('ended', this.restart);
        this.audioTarget.removeEventListener('play', this.onPlay);
        this.audioTarget.removeEventListener('pause', this.onPause);
        this.audioTarget.pause();
    }

    visibilityChanged(entry) {
        this.visible = entry.isIntersecting;
        if (!this.visible) {
            this.audioTarget.pause();
            this.render();
            return;
        }
        if (soundOn()) this.play();
    }

    // Pas de musique derrière un avertissement de contenu, ni si l'utilisateur limite les animations.
    get blocked() {
        return reducedMotion.matches || this.element.querySelector('.cw-veil') !== null;
    }

    play() {
        if (this.blocked) return this.render();
        const audio = this.audioTarget;
        if (audio.readyState === 0 || audio.currentTime < this.startValue || audio.currentTime >= this.startValue + this.excerptValue) {
            this.seekStart();
        }
        audio.play().then(() => this.render()).catch(() => {
            // Le navigateur refuse le son sans geste de l'utilisateur : on attend un clic sur le bouton.
            this.render();
        });
    }

    seekStart() {
        const audio = this.audioTarget;
        if (audio.readyState === 0) {
            audio.preload = 'auto';
            audio.addEventListener('loadedmetadata', () => { audio.currentTime = this.startValue; }, { once: true });
        } else {
            audio.currentTime = this.startValue;
        }
    }

    // Extrait en boucle.
    loop = () => {
        if (this.audioTarget.currentTime >= this.startValue + this.excerptValue) this.restart();
    };

    restart = () => {
        this.audioTarget.currentTime = this.startValue;
        if (this.visible && soundOn()) this.audioTarget.play().catch(() => {});
    };

    onPlay = () => {
        playAlone(this.audioTarget);
        this.render();
    };

    onPause = () => this.render();

    toggleSound(event) {
        event.stopPropagation();
        const playing = !this.audioTarget.paused;
        setSound(!playing);
        // Geste de l'utilisateur : le navigateur autorise la lecture même si le son était déjà « activé ».
        if (!playing) this.play();
    }

    applySound(on) {
        if (on && this.visible) this.play();
        if (!on) this.audioTarget.pause();
        this.render();
    }

    render() {
        const playing = !this.audioTarget.paused;
        this.element.classList.toggle('music-on', playing);
        this.soundTarget.setAttribute('aria-pressed', String(playing));
        this.soundTarget.setAttribute('aria-label', playing ? 'Couper la musique' : 'Écouter la musique');
    }
}
