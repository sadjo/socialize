import { Controller } from '@hotwired/stimulus';

/*
 * Sélecteur de musique des écrans de création : recherche dans Openverse (page de résultats rendue
 * par le serveur), choix d'un morceau (copié sur l'instance), puis réglage du début de l'extrait,
 * qu'on peut écouter avant de publier.
 */
const ZERO_ID = '00000000-0000-0000-0000-000000000000';

function clock(seconds) {
    const s = Math.max(0, Math.round(seconds));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

export default class extends Controller {
    static targets = ['track', 'start', 'chosen', 'searchBox', 'query', 'results', 'error', 'title', 'credit', 'range', 'startLabel', 'audio', 'play'];
    static values = { searchUrl: String, chooseUrl: String, token: String, excerpt: Number };

    connect() {
        this.onTime = () => {
            if (this.audioTarget.currentTime >= this.startValue() + this.excerptValue) this.stopPreview();
        };
        this.audioTarget.addEventListener('timeupdate', this.onTime);
        this.audioTarget.addEventListener('ended', () => this.stopPreview());
        this.renderStart();
    }

    disconnect() {
        this.audioTarget.removeEventListener('timeupdate', this.onTime);
        this.audioTarget.pause();
    }

    startValue() {
        return Number(this.rangeTarget.value) || 0;
    }

    async search() {
        await this.load(1, false);
    }

    async more({ params: { page } }) {
        await this.load(page, true);
    }

    async load(page, append) {
        const query = this.queryTarget.value.trim();
        if (!query) return;
        this.showError(null);
        if (!append) this.resultsTarget.innerHTML = '<p class="small">Recherche…</p>';
        const url = `${this.searchUrlValue}?${new URLSearchParams({ q: query, page })}`;
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            const html = await response.text();
            if (append) {
                this.resultsTarget.querySelector(':scope > button[data-action*="more"]')?.remove();
                this.resultsTarget.insertAdjacentHTML('beforeend', html);
            } else {
                this.resultsTarget.innerHTML = html;
            }
        } catch {
            if (!append) this.resultsTarget.replaceChildren();
            this.showError('La recherche de musique a échoué. Réessayez dans un instant.');
        }
    }

    async choose({ params: { id }, currentTarget }) {
        this.showError(null);
        const label = currentTarget.textContent;
        currentTarget.disabled = true;
        currentTarget.textContent = 'Préparation…';
        try {
            const response = await fetch(this.chooseUrlValue.replace(ZERO_ID, id), {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || 'Ce morceau n’a pas pu être préparé.');
            this.select(data);
        } catch (error) {
            this.showError(error.message);
        } finally {
            currentTarget.disabled = false;
            currentTarget.textContent = label;
        }
    }

    select(track) {
        this.trackTarget.value = track.id;
        this.titleTarget.textContent = track.title;
        this.creditTarget.textContent = `${track.artist} · ${track.license}`;
        this.rangeTarget.max = String(Math.max(0, track.duration - this.excerptValue));
        this.rangeTarget.value = '0';
        this.audioTarget.src = track.src;
        this.chosenTarget.hidden = false;
        this.searchBoxTarget.hidden = true;
        this.renderStart();
    }

    remove() {
        this.stopPreview();
        this.trackTarget.value = '';
        this.startTarget.value = '';
        this.audioTarget.removeAttribute('src');
        this.chosenTarget.hidden = true;
        this.searchBoxTarget.hidden = false;
        this.queryTarget.focus();
    }

    seek() {
        this.renderStart();
        if (!this.audioTarget.paused) this.audioTarget.currentTime = this.startValue();
    }

    togglePreview() {
        const audio = this.audioTarget;
        if (!audio.paused) return this.stopPreview();
        audio.currentTime = this.startValue();
        audio.play().then(() => this.renderPlay(true)).catch(() => this.renderPlay(false));
    }

    stopPreview() {
        this.audioTarget.pause();
        this.renderPlay(false);
    }

    renderStart() {
        this.startTarget.value = this.trackTarget.value ? String(this.startValue()) : '';
        this.startLabelTarget.textContent = `${clock(this.startValue())} – ${clock(this.startValue() + this.excerptValue)}`;
    }

    renderPlay(playing) {
        this.playTarget.querySelector('use').setAttribute('href', playing ? '#i-pause' : '#i-play');
        this.playTarget.setAttribute('aria-label', playing ? 'Arrêter l’écoute' : 'Écouter l’extrait');
    }

    showError(message) {
        this.errorTarget.hidden = !message;
        this.errorTarget.textContent = message || '';
    }
}
