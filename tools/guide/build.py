#!/usr/bin/env python3
"""Guide d'utilisation (docs/guide) assemblé pour le wiki GitHub et pour le site (GitHub Pages).

La même source est affichée dans l'application (/aide, src/Web/Help/HelpGuide.php). Les ancres des titres suivent la
même règle partout (celle de league/commonmark et du wiki GitHub) : « Créer un groupe » → créer-un-groupe.

    build.py wiki <dossier>                       User-Guide.md (anglais) et Guide-d-utilisation.md (français)
    build.py site <dossier> --template <gabarit>  guide/… (anglais) et guide/fr/… (français)

Dépendances : pyyaml, et markdown pour le site.
"""
from __future__ import annotations

import argparse
import html
import re
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'docs' / 'guide'
LANGUAGES = ('en', 'fr')
WIKI_PAGES = {'en': 'User-Guide.md', 'fr': 'Guide-d-utilisation.md'}
LABELS = {
    'en': {'guide': 'User Guide', 'intro': 'How to use Socialize: posting, sharing with your people, following accounts '
                                         'here and in the fediverse.', 'contents': 'Contents', 'admin': 'administrators',
           'other': 'Français', 'previous': 'Previous', 'next': 'Next', 'home': 'Home'},
    'fr': {'guide': "Guide d'utilisation", 'intro': "Utiliser Socialize : publier, partager avec ses proches, suivre "
                                                   "des comptes d'ici et du fédivers.", 'contents': 'Sommaire',
           'admin': 'administrateurs', 'other': 'English', 'previous': 'Précédent', 'next': 'Suivant',
           'home': 'Accueil'},
}
LINK = re.compile(r'\]\(([a-z0-9-]+)\.md(#[^)\s]*)?\)')
HEADING = re.compile(r'^(#{1,6})[ \t]+(.*?)[ \t]*$')


def slug(text: str) -> str:
    """Ancre d'un titre, comme league/commonmark (SlugNormalizer) et le wiki GitHub."""
    text = re.sub(r'[*_`]', '', text)
    text = re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', text)
    text = re.sub(r'\s+', '-', text.strip().lower())
    return re.sub(r'[^\w-]|_', '', text)


def headings(text: str) -> list[tuple[int, str]]:
    """Titres d'un chapitre (niveau, texte), hors blocs de code."""
    found, fence = [], False
    for line in text.split('\n'):
        if line.startswith('```'):
            fence = not fence
        elif not fence and (m := HEADING.match(line)):
            found.append((len(m.group(1)), m.group(2)))
    return found


def demote(text: str) -> str:
    """Chaque titre descend d'un niveau (le titre du chapitre devient un titre de niveau 2), hors blocs de code."""
    lines, fence = [], False
    for line in text.split('\n'):
        if line.startswith('```'):
            fence = not fence
        elif not fence and (m := HEADING.match(line)):
            line = '#' * min(len(m.group(1)) + 1, 6) + ' ' + m.group(2)
        lines.append(line)
    return '\n'.join(lines)


def chapters() -> list[dict]:
    return yaml.safe_load((SOURCE / 'guide.yaml').read_text(encoding='utf-8'))['chapters']


def read(language: str, chapter: dict) -> str:
    return (SOURCE / language / f"{chapter['slug']}.md").read_text(encoding='utf-8').strip() + '\n'


def check(language: str, items: list[dict]) -> None:
    """Liens entre chapitres vers des titres existants, titres uniques dans tout le guide (page unique du wiki)."""
    anchors: dict[str, list[str]] = {}
    for chapter in items:
        anchors[chapter['slug']] = [slug(h) for _, h in headings(read(language, chapter))]
    every = [a for values in anchors.values() for a in values]
    duplicates = sorted({a for a in every if every.count(a) > 1})
    errors = [f'{language} : titre en double « {a} »' for a in duplicates]
    for chapter in items:
        for target, anchor in LINK.findall(read(language, chapter)):
            if target not in anchors or (anchor and anchor[1:] not in anchors[target]):
                errors.append(f"{language}/{chapter['slug']}.md : lien {target}.md{anchor} introuvable")
    if errors:
        sys.exit('\n'.join(errors))


def wiki(out: Path) -> None:
    items = chapters()
    out.mkdir(parents=True, exist_ok=True)
    for language in LANGUAGES:
        check(language, items)
        labels = LABELS[language]
        titles = {c['slug']: slug(c['title'][language]) for c in items}
        parts = [f"# {labels['guide']}\n\n{labels['intro']}\n\n**{labels['contents']}**\n"]
        for c in items:
            suffix = f" ({labels['admin']})" if c['audience'] == 'admin' else ''
            parts.append(f"- [{c['title'][language]}](#{titles[c['slug']]}){suffix}")
        parts.append('')
        for c in items:
            text = read(language, c)
            text = demote(text)
            text = LINK.sub(lambda m: f"]({m.group(2) or '#' + titles[m.group(1)]})", text)
            parts.append(text)
        (out / WIKI_PAGES[language]).write_text('\n'.join(parts), encoding='utf-8')


def site(out: Path, template: Path) -> None:
    import markdown

    items = chapters()
    page = template.read_text(encoding='utf-8')
    for language in LANGUAGES:
        check(language, items)
        labels = LABELS[language]
        base = out / 'guide' / ('fr' if language == 'fr' else '')
        base.mkdir(parents=True, exist_ok=True)
        depth = '../../' if language == 'fr' else '../'
        other = 'en' if language == 'fr' else 'fr'
        other_root = '../' if language == 'fr' else 'fr/'

        def render(content: str, title: str, file: str, sidebar_current: str | None) -> None:
            toc = ''.join(
                f'<li{" class=on" if c["slug"] == sidebar_current else ""}><a href="{c["slug"]}.html">'
                f'{html.escape(c["title"][language])}</a></li>' for c in items)
            # Page d'accueil du guide : le sommaire est le contenu, sans colonne latérale.
            side = '' if sidebar_current is None else (
                f'<nav class="side" aria-label="{labels["contents"]}"><b>{labels["contents"]}</b><ol>{toc}</ol></nav>')
            values = {
                'lang': language, 'title': html.escape(title), 'guide': html.escape(labels['guide']),
                'root': depth, 'side': side, 'layout': ' single' if sidebar_current is None else '', 'content': content,
                'other_label': labels['other'], 'other_href': other_root + file, 'other_lang': other,
                'home': labels['home'],
            }
            text = page
            for key, value in values.items():
                text = text.replace('{{ ' + key + ' }}', value)
            (base / file).write_text(text, encoding='utf-8')

        intro = [f"<h1>{html.escape(labels['guide'])}</h1><p class=lead>{html.escape(labels['intro'])}</p><ol class=chapters>"]
        for c in items:
            suffix = f" <small>· {labels['admin']}</small>" if c['audience'] == 'admin' else ''
            intro.append(f'<li><a href="{c["slug"]}.html">{html.escape(c["title"][language])}</a>{suffix}</li>')
        intro.append('</ol>')
        render(''.join(intro), labels['guide'], 'index.html', None)

        for i, c in enumerate(items):
            text = LINK.sub(lambda m: f"]({m.group(1)}.html{m.group(2) or ''})", read(language, c))
            body = markdown.markdown(
                text, extensions=['tables', 'fenced_code', 'sane_lists', 'toc'],
                extension_configs={'toc': {'slugify': lambda value, separator: slug(value)}})
            nav = ['<nav class=pager>']
            if i > 0:
                nav.append(f'<a class=btn href="{items[i - 1]["slug"]}.html">← {html.escape(items[i - 1]["title"][language])}</a>')
            nav.append('<span></span>')
            if i + 1 < len(items):
                nav.append(f'<a class=btn href="{items[i + 1]["slug"]}.html">{html.escape(items[i + 1]["title"][language])} →</a>')
            nav.append('</nav>')
            render(body + ''.join(nav), c['title'][language], f"{c['slug']}.html", c['slug'])


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('target', choices=['wiki', 'site'])
    parser.add_argument('out', type=Path)
    parser.add_argument('--template', type=Path, help='gabarit HTML des pages du site')
    args = parser.parse_args()
    if args.target == 'wiki':
        wiki(args.out)
    else:
        if args.template is None:
            parser.error('--template est requis pour le site')
        site(args.out, args.template)


if __name__ == '__main__':
    main()
