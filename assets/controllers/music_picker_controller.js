/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Sélecteur de musique des écrans de création : recherche dans Openverse (page de résultats rendue
 * par le serveur), pré-écoute relayée par le serveur sans rien stocker, choix d'un morceau et du début
 * de l'extrait. Le morceau n'est copié sur l'instance qu'à la publication.
 *
 * Avant toute recherche, la liste montre les favoris du compte (cœur sur un résultat pour l'y ajouter).
 *
 * Sur un écran de bureau, la recherche s'ouvre dans une fenêtre au centre (page assombrie) ;
 * sur mobile, elle reste dans la page. Le même bloc de recherche passe de l'un à l'autre.
 */
const ZERO_ID = '00000000-0000-0000-0000-000000000000';
const desktop = window.matchMedia('(min-width: 768px)');

function clock(seconds) {
    const s = Math.max(0, Math.round(seconds));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

export default class extends Controller {
    static targets = ['track', 'start', 'chosen', 'searchBox', 'query', 'results', 'error', 'title', 'credit', 'range', 'startLabel', 'audio', 'play', 'opener', 'dialog', 'dialogBody'];
    static values = { searchUrl: String, listenUrl: String, favoritesUrl: String, favoriteUrl: String, token: String, excerpt: Number };

    connect() {
        // Lecteur de l'extrait choisi, sur le début réglé.
        this.onTime = () => {
            if (this.audioTarget.currentTime >= this.startValue() + this.excerptValue) this.stopPreview();
        };
        this.audioTarget.addEventListener('timeupdate', this.onTime);
        this.audioTarget.addEventListener('ended', () => this.stopPreview());
        this.audioTarget.addEventListener('error', () => {
            if (this.audioTarget.getAttribute('src')) this.showError('Ce morceau ne peut pas être écouté pour le moment.');
            this.stopPreview();
        });

        // Lecteur des résultats de recherche (un morceau à la fois, l'extrait du début).
        this.listenToken = 0;
        this.request = 0;
        this.listener = new Audio();
        this.listener.preload = 'none';
        this.listener.addEventListener('timeupdate', () => { if (this.listener.currentTime >= this.excerptValue) this.stopListening(); });
        this.listener.addEventListener('ended', () => this.stopListening());
        // Son lancé : égaliseur animé sur la vignette du morceau écouté.
        this.listener.addEventListener('playing', () => {
            this.listening?.classList.remove('loading');
            this.listening?.closest('li')?.classList.add('playing');
        });
        this.listener.addEventListener('error', () => {
            if (this.listening) this.showError('Ce morceau ne peut pas être écouté pour le moment.');
            this.stopListening();
        });

        // Emplacement du bloc de recherche dans la page, pour l'y remettre en passant au format mobile.
        this.anchor = document.createComment('recherche de musique');
        this.searchBoxTarget.before(this.anchor);
        this.onLayout = () => this.place();
        desktop.addEventListener('change', this.onLayout);
        this.place();
        this.renderStart();
        // Mobile : la recherche est déjà dans la page, les favoris s'y affichent tout de suite.
        if (!desktop.matches && !this.searchBoxTarget.hidden) this.showFavorites();
    }

    disconnect() {
        desktop.removeEventListener('change', this.onLayout);
        this.audioTarget.removeEventListener('timeupdate', this.onTime);
        this.audioTarget.pause();
        this.stopListening();
        if (this.dialogTarget.open) this.dialogTarget.close();
    }

    // Bureau : recherche dans la fenêtre ; mobile : dans la page.
    place() {
        if (desktop.matches) {
            this.dialogBodyTarget.append(this.searchBoxTarget);
        } else {
            if (this.dialogTarget.open) this.dialogTarget.close();
            this.anchor.after(this.searchBoxTarget);
        }
        this.render();
    }

    render() {
        const chosen = this.trackTarget.value !== '';
        this.chosenTarget.hidden = !chosen;
        this.openerTarget.hidden = chosen || !desktop.matches;
        this.searchBoxTarget.hidden = desktop.matches ? false : chosen;
    }

    open() {
        this.showError(null);
        this.dialogTarget.showModal();
        this.queryTarget.focus();
        if (!this.queryTarget.value.trim()) this.showFavorites();
    }

    // Recherche effacée : retour aux favoris.
    queryChanged() {
        if (!this.queryTarget.value.trim()) this.showFavorites();
    }

    async showFavorites() {
        this.stopListening();
        this.showError(null);
        const request = ++this.request;
        try {
            const response = await fetch(this.favoritesUrlValue, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            const html = await response.text();
            // Seule la dernière demande (favoris ou recherche) remplit la liste.
            if (request === this.request) this.resultsTarget.innerHTML = html;
        } catch {
            if (request === this.request) this.resultsTarget.replaceChildren();
        }
    }

    async favorite({ params: { id }, currentTarget }) {
        this.showError(null);
        currentTarget.disabled = true;
        try {
            const response = await fetch(this.favoriteUrlValue.replace(ZERO_ID, String(id)), {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || 'Le favori n’a pas pu être enregistré.');
            currentTarget.setAttribute('aria-pressed', String(data.favorite));
            currentTarget.setAttribute('aria-label', data.favorite ? 'Retirer des favoris' : 'Ajouter aux favoris');
        } catch (error) {
            this.showError(error.message);
        } finally {
            currentTarget.disabled = false;
        }
    }

    close() {
        this.dialogTarget.close();
    }

    // Clic sur le fond assombri : fermeture.
    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }

    closed() {
        this.stopListening();
    }

    listenUrl(id) {
        return this.listenUrlValue.replace(ZERO_ID, id);
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
        if (!query) return this.showFavorites();
        this.showError(null);
        this.stopListening();
        if (!append) this.resultsTarget.innerHTML = '<p class="small">Recherche…</p>';
        const url = `${this.searchUrlValue}?${new URLSearchParams({ q: query, page })}`;
        // Une recherche Openverse peut prendre plusieurs secondes : si la recherche est effacée
        // ou remplacée entre-temps, sa réponse arrivée en retard est ignorée.
        const request = ++this.request;
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            const html = await response.text();
            if (request !== this.request) return;
            if (append) {
                this.resultsTarget.querySelector(':scope > button[data-action*="more"]')?.remove();
                this.resultsTarget.insertAdjacentHTML('beforeend', html);
            } else {
                this.resultsTarget.innerHTML = html;
            }
        } catch {
            if (request !== this.request) return;
            if (!append) this.resultsTarget.replaceChildren();
            this.showError('La recherche de musique a échoué. Réessayez dans un instant.');
        }
    }

    // Pré-écoute d'un résultat : ▶ lance, ▮▮ arrête ; un autre résultat remplace le précédent.
    listen({ params: { id }, currentTarget }) {
        this.showError(null);
        const same = this.listening === currentTarget;
        this.stopListening();
        if (same) return;
        this.audioTarget.pause();
        this.renderPlay(false);
        this.listening = currentTarget;
        this.setIcon(currentTarget, true);
        // En attendant le son (Openverse et le fournisseur peuvent mettre quelques secondes).
        currentTarget.classList.add('loading');
        const token = ++this.listenToken;
        this.listener.src = this.listenUrl(String(id));
        this.listener.play().catch((error) => {
            // Lecture abandonnée parce qu'un autre morceau a été lancé : rien à faire.
            if (token === this.listenToken && error.name !== 'AbortError') this.stopListening();
        });
    }

    // Pas de pochette (ou vignette indisponible) : l'icône de note reste visible.
    thumbnailMissing({ currentTarget }) {
        currentTarget.remove();
    }

    // Arrête la pré-écoute et ferme sa connexion : le serveur cesse aussitôt de relayer le morceau.
    stopListening() {
        if (!this.listener) return;
        const button = this.listening;
        this.listening = null;
        this.listenToken = (this.listenToken || 0) + 1;
        if (button) {
            this.setIcon(button, false);
            button.classList.remove('loading');
            button.closest('li')?.classList.remove('playing');
        }
        this.listener.pause();
        if (this.listener.getAttribute('src')) {
            this.listener.removeAttribute('src');
            this.listener.load();
        }
    }

    choose({ params }) {
        this.showError(null);
        this.stopListening();
        this.trackTarget.value = String(params.id);
        this.titleTarget.textContent = String(params.title);
        this.creditTarget.textContent = `${params.artist} · ${params.license}`;
        this.rangeTarget.max = String(Math.max(0, Number(params.duration) - this.excerptValue));
        this.rangeTarget.value = '0';
        this.audioTarget.src = this.listenUrl(String(params.id));
        if (this.dialogTarget.open) this.close();
        this.render();
        this.renderStart();
    }

    remove() {
        this.stopPreview();
        this.trackTarget.value = '';
        this.startTarget.value = '';
        this.audioTarget.removeAttribute('src');
        this.render();
        (desktop.matches ? this.openerTarget : this.queryTarget).focus();
        if (!desktop.matches && !this.queryTarget.value.trim()) this.showFavorites();
    }

    seek() {
        this.renderStart();
        if (!this.audioTarget.paused) this.audioTarget.currentTime = this.startValue();
    }

    togglePreview() {
        const audio = this.audioTarget;
        if (!audio.paused) return this.stopPreview();
        this.stopListening();
        this.showError(null);
        const start = () => { audio.currentTime = this.startValue(); };
        if (audio.readyState === 0) audio.addEventListener('loadedmetadata', start, { once: true });
        else start();
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
        this.setIcon(this.playTarget, playing);
        this.playTarget.setAttribute('aria-label', playing ? 'Arrêter l’écoute' : 'Écouter l’extrait');
    }

    setIcon(button, playing) {
        button.querySelector('use')?.setAttribute('href', playing ? '#i-pause' : '#i-play');
        button.setAttribute('aria-pressed', String(playing));
    }

    showError(message) {
        this.errorTarget.hidden = !message;
        this.errorTarget.textContent = message || '';
    }
}
