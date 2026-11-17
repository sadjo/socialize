import { Controller } from '@hotwired/stimulus';

/* Suggestions de mentions dans une légende : dès « @ », les comptes suivis puis d'autres comptes d'ici ; en tapant
   « @ca… », les comptes d'ici et ceux du fédivers déjà connus ; en tapant « ig:@ca… », le compte Instagram. Flèches pour choisir, Entrée ou Tab pour insérer, Échap
   pour fermer. Le contrôleur est posé sur la zone de texte elle-même. */
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
    }

    disconnect() {
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
        return { start: before.length - match[2].length, end: this.element.selectionStart, instagram: Boolean(match[3]), name: match[4], domain: (match[5] || '').slice(1) };
    }

    lookup() {
        clearTimeout(this.timer);
        const token = this.token();
        if (!token || (token.instagram && token.name === '')) {
            this.close();
            return;
        }
        this.current = token;
        if (token.instagram) {
            this.show([{ mention: `ig:@${token.name}`, name: `@${token.name}`, handle: 'Compte Instagram', instagram: true }]);
            return;
        }
        this.timer = setTimeout(async () => {
            const query = token.domain ? `${token.name}@${token.domain}` : token.name;
            try {
                const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
                const accounts = response.ok ? await response.json() : [];
                // Avec un nom tapé, toujours la possibilité d'un compte Instagram de ce nom.
                const suggestions = token.name === '' ? accounts : [...accounts, { mention: `ig:@${token.name}`, name: `@${token.name}`, handle: 'Compte Instagram', instagram: true }];
                if (this.current !== token) return;
                suggestions.length ? this.show(suggestions) : this.close();
            } catch {
                this.close();
            }
        }, token.name === '' ? 0 : 150);
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
        if (item.instagram) {
            picture = document.createElement('span');
            picture.className = 'ig-badge';
            picture.textContent = 'IG';
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
        if (this.list.hidden) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.active = (this.active + step + this.items.length) % this.items.length;
            this.highlight();
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            this.pick(this.active);
        } else if (event.key === 'Escape') {
            event.preventDefault();
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

    /* Remplace la saisie par la mention choisie, suivie d'une espace. */
    pick(index) {
        const item = this.items[index];
        const token = this.token() || this.current;
        if (!item || !token) return;
        const text = `${item.mention} `;
        this.element.setRangeText(text, token.start, token.end, 'end');
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
}
