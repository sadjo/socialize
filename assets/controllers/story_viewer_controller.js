import { Controller } from '@hotwired/stimulus';

const HOLD_DELAY = 250;

/*
 * Visionneuse de stories en plein écran.
 * Toutes les stories de la session sont dans une seule page : le fond assombri ne bouge pas,
 * seule l'image (préchargée avant l'échange) ou la vidéo change, sans scintillement.
 */
export default class extends Controller {
    static targets = ['frame', 'media', 'bars', 'author', 'name', 'meta', 'text', 'own', 'pause', 'mute', 'close'];
    static values = { data: Object };

    connect() {
        this.groups = this.dataValue.groups;
        this.g = this.dataValue.start;
        this.i = this.groups[this.g]?.start ?? 0;
        this.paused = false;
        this.muted = true;
        this.elapsed = 0;
        this.seen = new Set();
        this.token = 0;
        this.onVisibility = () => (document.hidden ? this.pause() : this.resume());
        document.addEventListener('visibilitychange', this.onVisibility);
        if (this.groups.length === 0) return this.close();
        this.show();
    }

    disconnect() {
        cancelAnimationFrame(this.raf);
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    get group() { return this.groups[this.g]; }
    get story() { return this.group.stories[this.i]; }

    show() {
        const token = ++this.token;
        const story = this.story;
        cancelAnimationFrame(this.raf);
        this.elapsed = 0;
        this.renderChrome();

        let swapped = false;
        const swap = (element) => {
            if (token !== this.token || swapped) return;
            swapped = true;
            this.mediaTarget.replaceChildren(element);
            this.markSeen(story);
            this.preloadNext();
            this.started = performance.now();
            this.tick();
        };

        if (story.type === 'video') {
            const video = document.createElement('video');
            video.playsInline = true;
            video.muted = this.muted;
            video.preload = 'auto';
            video.poster = story.poster || '';
            video.src = story.src;
            video.addEventListener('ended', () => this.next());
            const start = () => { swap(video); if (!this.paused) video.play().catch(() => {}); };
            video.addEventListener('loadeddata', start, { once: true });
            video.addEventListener('error', start, { once: true });
            video.load();
            this.video = video;
            setTimeout(start, 3000);
        } else {
            this.video = null;
            const img = new Image();
            img.alt = '';
            // L'image remplace la précédente une fois chargée : pas d'écran vide entre deux stories.
            img.onload = () => swap(img);
            img.onerror = () => swap(img);
            img.src = story.src;
            if (img.complete) swap(img);
            setTimeout(() => swap(img), 3000);
        }
    }

    renderChrome() {
        const group = this.group;
        const story = this.story;

        this.barsTarget.replaceChildren(...group.stories.map((_, index) => {
            const bar = document.createElement('i');
            bar.className = index < this.i ? 'done' : '';
            bar.appendChild(document.createElement('span'));
            return bar;
        }));

        const avatar = group.avatar
            ? Object.assign(document.createElement('img'), { src: group.avatar, alt: '', className: 'avatar-img', width: 32, height: 32 })
            : Object.assign(document.createElement('span'), { className: 'av', textContent: group.initials });
        if (!group.avatar) avatar.style.cssText = `--h:${group.hue};width:32px;height:32px;font-size:12px`;
        this.authorTarget.replaceChildren(avatar);
        this.authorTarget.href = group.profileUrl;
        this.nameTarget.textContent = group.username;
        this.metaTarget.textContent = `· ${story.ago}${this.dataValue.highlight ? ` · ${this.dataValue.highlight}` : ''}${story.audience === 'followers' ? ' · abonnés' : ''}`;

        this.textTarget.hidden = !story.text;
        this.textTarget.textContent = story.text || '';

        this.ownTarget.hidden = !group.own;
        if (group.own) {
            const link = Object.assign(document.createElement('a'), { href: this.dataValue.manageUrl, className: 'btn sm', textContent: 'Gérer mes stories' });
            const views = Object.assign(document.createElement('span'), { textContent: `Vue par ${story.views} personne${story.views > 1 ? 's' : ''}` });
            this.ownTarget.replaceChildren(views, link);
        }

        this.muteTarget.hidden = story.type !== 'video';
        this.updateButtons();
    }

    tick = () => {
        const story = this.story;
        if (!this.paused) {
            this.elapsed = this.video ? this.video.currentTime : this.elapsed + (performance.now() - this.started) / 1000;
            this.started = performance.now();
        }
        const duration = this.video && Number.isFinite(this.video.duration) ? this.video.duration : story.duration;
        const progress = Math.min(1, this.elapsed / duration);
        const bar = this.barsTarget.children[this.i]?.firstElementChild;
        if (bar) bar.style.width = `${progress * 100}%`;

        if (!this.video && progress >= 1) return this.next();
        this.raf = requestAnimationFrame(this.tick);
    };

    next() {
        if (this.i < this.group.stories.length - 1) {
            this.i++;
        } else if (this.g < this.groups.length - 1) {
            this.g++;
            this.i = this.group.start;
        } else {
            return this.close();
        }
        this.show();
    }

    prev() {
        if (this.i > 0) {
            this.i--;
        } else if (this.g > 0) {
            this.g--;
            this.i = this.group.stories.length - 1;
        }
        this.show();
    }

    hold() {
        this.holdStart = performance.now();
        this.holdTimer = setTimeout(() => this.pause(), HOLD_DELAY);
    }

    release() {
        if (this.holdStart === undefined) return;
        clearTimeout(this.holdTimer);
        const short = performance.now() - this.holdStart < HOLD_DELAY;
        this.holdStart = undefined;
        if (short) return this.next();
        this.resume();
    }

    togglePause() { this.paused ? this.resume() : this.pause(); }

    pause() {
        this.paused = true;
        this.video?.pause();
        this.updateButtons();
    }

    resume() {
        if (document.hidden) return;
        this.paused = false;
        this.started = performance.now();
        this.video?.play().catch(() => {});
        this.updateButtons();
    }

    toggleMute() {
        this.muted = !this.muted;
        if (this.video) this.video.muted = this.muted;
        this.updateButtons();
    }

    updateButtons() {
        this.pauseTarget.querySelector('use').setAttribute('href', this.paused ? '#i-play' : '#i-pause');
        this.pauseTarget.setAttribute('aria-label', this.paused ? 'Reprendre' : 'Mettre en pause');
        this.muteTarget.querySelector('use').setAttribute('href', this.muted ? '#i-mute' : '#i-volume');
        this.muteTarget.setAttribute('aria-label', this.muted ? 'Activer le son' : 'Couper le son');
    }

    key(event) {
        if (event.key === 'ArrowRight') this.next();
        else if (event.key === 'ArrowLeft') this.prev();
        else if (event.key === 'Escape') this.close();
        else if (event.key === ' ') { event.preventDefault(); this.togglePause(); }
    }

    close() {
        cancelAnimationFrame(this.raf);
        window.location.href = this.dataValue.closeUrl;
    }

    markSeen(story) {
        if (!story.seenUrl || story.seen || this.seen.has(story.id)) return;
        this.seen.add(story.id);
        story.seen = true;
        const body = new FormData();
        body.append('_token', this.dataValue.viewToken);
        fetch(story.seenUrl, { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(() => {});
    }

    preloadNext() {
        const next = this.group.stories[this.i + 1] ?? this.groups[this.g + 1]?.stories[this.groups[this.g + 1].start];
        if (next && next.type === 'image') {
            const img = new Image();
            img.src = next.src;
        }
    }
}
