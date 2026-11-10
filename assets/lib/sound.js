/*
 * Son des médias du fil, partagé par les vidéos et les musiques : activé sur l'un, il l'est pour
 * les suivants et pour la session ; coupé, les suivants démarrent sans le son.
 */
const SOUND_KEY = 'socialize.sound';
export const SOUND_EVENT = 'socialize:sound';

export function soundOn() {
    try { return sessionStorage.getItem(SOUND_KEY) === 'on'; } catch { return false; }
}

export function setSound(on) {
    try { sessionStorage.setItem(SOUND_KEY, on ? 'on' : 'off'); } catch { /* stockage indisponible */ }
    window.dispatchEvent(new CustomEvent(SOUND_EVENT, { detail: { on } }));
}

/*
 * Une seule publication joue à la fois : les vidéos et musiques des autres publications se mettent
 * en pause. Celles de la même publication (vidéo muette sous sa musique) continuent.
 */
export function playAlone(element) {
    const media = element.closest('.media');
    document.querySelectorAll('.player video, audio.post-music').forEach((other) => {
        if (other !== element && !other.paused && (!media || other.closest('.media') !== media)) other.pause();
    });
}
