import { Controller } from '@hotwired/stimulus';

/* Mentions, comme côté serveur (Mentions::PATTERN) : « ig: » éventuel, nom, domaine éventuel. */
const MENTION = /(?<![\w/])(?:(ig:)|(?<!ig:))@([a-z0-9_.]{1,30})(@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})?/gi;
const MIRRORED = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'wordSpacing', 'textTransform', 'textIndent', 'tabSize',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
    'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomLeftRadius', 'borderBottomRightRadius', 'borderStyle'];

const escape = (text) => text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

/* Suggestions de mentions dans une légende : dès « @ », les comptes suivis puis d'autres comptes d'ici ; en tapant
   « @ca… », les comptes d'ici et ceux du fédivers déjà connus ; en tapant « ig:@ca… », le compte Instagram. Flèches pour choisir, Entrée ou Tab pour insérer, Échap
   pour fermer : la liste ne se rouvre alors, pour cette mention, qu'avec Ctrl+Espace. Le contrôleur est posé sur la
   zone de texte elle-même ; une copie mise en forme, placée dessous, montre les mentions avec leur pictogramme. */
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.items = [];
        this.active = -1;
        this.list = document.createElement('ul');
        this.list.className = 'mention-list';
        this.list.id = `${this.element.id || 'caption'}-mentions`;
        this.list.setAttribute('role', 'listbox');
        this.list.setAttribute('aria-label', 'Comptes à mentionner');
        this.list.hidden = true;
        this.element.parentElement.classList.add('mention-host');
        this.element.insertAdjacentElement('afterend', this.list);
        this.element.setAttribute('aria-autocomplete', 'list');
        this.element.setAttribute('aria-controls', this.list.id);

        this.onInput = () => this.lookup();
        this.onKey = (event) => this.key(event);
        this.onBlur = () => setTimeout(() => this.close(), 150);
        this.element.addEventListener('input', this.onInput);
        this.element.addEventListener('click', this.onInput);
        this.element.addEventListener('keydown', this.onKey);
        this.element.addEventListener('blur', this.onBlur);
        this.list.addEventListener('mousedown', (event) => event.preventDefault());
        this.mirror();
    }

    disconnect() {
        this.resizeObserver?.disconnect();
        this.copy?.remove();
        this.element.classList.remove('mention-input');
        this.element.removeEventListener('input', this.onDraw);
        this.element.removeEventListener('scroll', this.onScroll);
        this.element.removeEventListener('focus', this.onDraw);
        this.element.removeEventListener('input', this.onInput);
        this.element.removeEventListener('click', this.onInput);
        this.element.removeEventListener('keydown', this.onKey);
        this.element.removeEventListener('blur', this.onBlur);
        this.list.remove();
        clearTimeout(this.timer);
    }

    /* Mention en cours de saisie juste avant le curseur : « @nom », « @nom@inst… » ou « ig:@nom ». */
    token() {
        const before = this.element.value.slice(0, this.element.selectionStart);
        const match = before.match(/(^|[\s(])((ig:)?@([a-z0-9_.]{0,30})(@[a-z0-9.-]*)?)$/i);
        if (!match) return null;
        // fediverse : second « @ » tapé, saisie d'un compte du fédivers (même sans domaine encore).
        return { start: before.length - match[2].length, end: this.element.selectionStart, instagram: Boolean(match[3]), name: match[4], fediverse: match[5] !== undefined, domain: (match[5] || '').slice(1) };
    }

    /* force : Ctrl+Espace, qui rouvre une liste fermée. */
    lookup(force = false) {
        clearTimeout(this.timer);
        const token = this.token();
        if (!token || (token.instagram && token.name === '')) {
            this.close();
            return;
        }
        if (force) {
            this.dismissedAt = null;
        } else if (this.dismissedAt === token.start) {
            return; // Liste fermée pour cette mention : seulement Ctrl+Espace.
        }
        this.current = token;
        if (token.instagram) {
            this.show([{ mention: `ig:@${token.name}`, name: `@${token.name}`, handle: 'Compte Instagram', network: 'ig' }]);
            return;
        }
        this.timer = setTimeout(async () => {
            const query = token.fediverse ? `${token.name}@${token.domain}` : token.name;
            try {
                const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
                const accounts = response.ok ? await response.json() : [];
                if (this.current !== token) return;
                const suggestions = [...accounts, ...this.typed(token, accounts)];
                if (!suggestions.length) {
                    this.close();
                    return;
                }
                this.show(suggestions);
            } catch {
                this.close();
            }
            // Domaine en cours de saisie : attente plus longue (une adresse complète est cherchée sur son serveur).
        }, token.name === '' ? 0 : (token.fediverse ? 400 : 150));
    }

    /* Comme pour Instagram, la saisie elle-même parmi les propositions :
       - « @hu » : « @hu@… », compte Mastodon (le choisir passe à la saisie de l'instance), et « ig:@hu » ;
       - « @hu@mastodon.social » : cette adresse, même si le compte n'est pas encore connu ici ;
       - « @hu@ » ou instance incomplète : la saisie en aide, qui ne s'insère pas. */
    typed(token, accounts) {
        if (token.name === '') return [];
        if (!token.fediverse) {
            return [
                { insert: `@${token.name}@`, name: `@${token.name}@…`, handle: 'Compte Mastodon : tapez ensuite l’instance', network: 'fedi' },
                { mention: `ig:@${token.name}`, name: `@${token.name}`, handle: 'Compte Instagram', network: 'ig' },
            ];
        }
        const address = `@${token.name}@${token.domain}`.toLowerCase();
        if (!/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/i.test(token.domain)) {
            return [{ hint: true, name: `${address}…`, handle: 'Compte Mastodon : tapez l’instance (ex. mastodon.social)', network: 'fedi' }];
        }
        if (accounts.some((account) => account.handle?.toLowerCase() === address)) return [];
        return [{ mention: address, name: address, handle: 'Compte Mastodon', network: 'fedi' }];
    }

    show(items) {
        this.items = items;
        this.active = 0;
        this.list.replaceChildren(...items.map((item, index) => this.option(item, index)));
        this.list.hidden = false;
        this.element.setAttribute('aria-expanded', 'true');
        this.highlight();
    }

    option(item, index) {
        const li = document.createElement('li');
        li.id = `${this.list.id}-${index}`;
        li.setAttribute('role', 'option');
        let picture;
        if (item.hint) {
            li.setAttribute('aria-disabled', 'true');
            li.classList.add('hint');
        }
        if (item.network) {
            // Saisie elle-même (compte Mastodon ou Instagram) : pictogramme du réseau.
            picture = document.createElement('span');
            picture.className = 'net-badge';
            const icon = document.createElement('span');
            icon.className = `mi mi-${item.network}`;
            picture.append(icon);
        } else if (item.avatar) {
            picture = document.createElement('img');
            picture.className = 'avatar-img';
            picture.src = item.avatar;
            picture.alt = '';
        } else {
            picture = document.createElement('span');
            picture.className = 'av';
            picture.style.setProperty('--h', item.hue);
            picture.textContent = item.initials;
        }
        const text = document.createElement('span');
        text.className = 't';
        const name = document.createElement('b');
        name.textContent = item.name;
        const handle = document.createElement('small');
        handle.textContent = item.handle;
        text.append(name, handle);
        li.append(picture, text);
        li.addEventListener('click', () => this.pick(index));
        return li;
    }

    key(event) {
        if (event.ctrlKey && (event.code === 'Space' || event.key === ' ')) {
            event.preventDefault();
            this.lookup(true);
            return;
        }
        if (this.list.hidden) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.active = (this.active + step + this.items.length) % this.items.length;
            this.highlight();
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            // Simple aide de saisie : la touche garde son effet habituel.
            if (this.items[this.active]?.hint) {
                this.close();
                return;
            }
            event.preventDefault();
            this.pick(this.active);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.dismissedAt = (this.token() || this.current)?.start ?? null;
            this.close();
        }
    }

    highlight() {
        [...this.list.children].forEach((li, index) => li.setAttribute('aria-selected', String(index === this.active)));
        const active = this.list.children[this.active];
        if (active) {
            this.element.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    /* Remplace la saisie par la mention choisie, suivie d'une espace ; « @hu@… » insère « @hu@ » et passe à la saisie
       de l'instance. */
    pick(index) {
        const item = this.items[index];
        const token = this.token() || this.current;
        if (!item || item.hint || !token) {
            if (item?.hint) this.close();
            return;
        }
        if (item.insert) {
            this.element.setRangeText(item.insert, token.start, token.end, 'end');
            this.element.focus();
            this.element.dispatchEvent(new Event('input', { bubbles: true })); // Nouvelle recherche : comptes du fédivers.
            return;
        }
        this.element.setRangeText(`${item.mention} `, token.start, token.end, 'end');
        this.element.dispatchEvent(new Event('input', { bubbles: true }));
        this.element.focus();
        this.close();
    }

    close() {
        clearTimeout(this.timer);
        this.list.hidden = true;
        this.items = [];
        this.current = null;
        this.element.setAttribute('aria-expanded', 'false');
        this.element.removeAttribute('aria-activedescendant');
    }

    /* Copie mise en forme sous la zone de texte : mêmes dimensions et même typographie, texte réel rendu transparent. */
    mirror() {
        this.copy = document.createElement('div');
        this.copy.className = 'mention-mirror';
        this.copy.setAttribute('aria-hidden', 'true');
        this.element.insertAdjacentElement('beforebegin', this.copy);
        this.place();
        this.element.classList.add('mention-input');
        this.onDraw = () => this.draw();
        this.onScroll = () => {
            this.copy.scrollTop = this.element.scrollTop;
        };
        this.element.addEventListener('input', this.onDraw);
        this.element.addEventListener('scroll', this.onScroll);
        this.element.addEventListener('focus', this.onDraw);
        this.resizeObserver = new ResizeObserver(() => this.place());
        this.resizeObserver.observe(this.element);
        this.draw();
    }

    place() {
        const style = getComputedStyle(this.element);
        for (const property of MIRRORED) this.copy.style[property] = style[property];
        // Fond de la zone de texte porté par la copie (la zone devient transparente).
        if (!this.element.classList.contains('mention-input')) this.copy.style.backgroundColor = style.backgroundColor;
        const border = parseFloat(style.borderLeftWidth) + parseFloat(style.borderRightWidth);
        Object.assign(this.copy.style, {
            top: `${this.element.offsetTop}px`,
            left: `${this.element.offsetLeft}px`,
            // Largeur sans la barre de défilement éventuelle : les lignes se coupent au même endroit.
            width: `${this.element.clientWidth + border}px`,
            height: `${this.element.offsetHeight}px`,
        });
        this.copy.scrollTop = this.element.scrollTop;
    }

    draw() {
        const text = this.element.value;
        let html = '';
        let last = 0;
        for (const match of text.matchAll(MENTION)) {
            const [all, instagram, name, domain = ''] = match;
            html += escape(text.slice(last, match.index));
            html += instagram
                ? `<span class="mt"><span class="ms">ig:<span class="mi mi-ig"></span></span>@${escape(name)}</span>${escape(domain)}`
                : `<span class="mt"><span class="ms">@<span class="mi ${domain ? 'mi-fedi' : 'mi-local'}"></span></span>${escape(name + domain)}</span>`;
            last = match.index + all.length;
        }
        // Un saut de ligne final ne compte dans la hauteur que suivi d'un caractère.
        this.copy.innerHTML = `${html}${escape(text.slice(last))} `;
        this.copy.scrollTop = this.element.scrollTop;
    }
}
