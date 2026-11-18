<?php

declare(strict_types=1);

namespace App\Web\Help;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Guide d'utilisation affiché dans l'application (/aide), en français. Source unique, aussi publiée sur le wiki et le
 * site (tools/guide/build.py) : docs/guide/guide.yaml (chapitres) et docs/guide/<langue>/<chapitre>.md.
 *
 * Les titres reçoivent un identifiant calculé comme les ancres du wiki GitHub (« Créer un groupe » → créer-un-groupe),
 * cible des liens entre chapitres (groupes.md#créer-un-groupe) et des « ? » de l'interface.
 */
final class HelpGuide
{
    public const string LANGUAGE = 'fr';

    /** @var list<HelpChapter>|null */
    private ?array $chapters = null;

    private ?MarkdownConverter $converter = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/docs/guide')]
        private readonly string $directory,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Chapitres dans l'ordre de lecture ; ceux de l'administration seulement pour un administrateur.
     *
     * @return list<HelpChapter>
     */
    public function chapters(bool $admin): array
    {
        return array_values(array_filter($this->all(), static fn (HelpChapter $c): bool => $admin || !$c->adminOnly));
    }

    public function chapter(string $slug, bool $admin): ?HelpChapter
    {
        return array_find($this->chapters($admin), static fn (HelpChapter $c): bool => $c->slug === $slug);
    }

    /**
     * HTML du chapitre, sans son titre (affiché par la page) ; liens vers les autres chapitres réécrits vers /aide.
     */
    public function html(HelpChapter $chapter, string $language = self::LANGUAGE): string
    {
        $markdown = (string) file_get_contents($this->path($chapter->slug, $language));
        $markdown = (string) preg_replace('/\A#\s[^\n]*\n+/', '', $markdown);
        $markdown = (string) preg_replace_callback(
            '/\]\(([a-z0-9-]+)\.md(#[^)\s]*)?\)/',
            fn (array $m): string => ']('.$this->urls->generate('app_help_page', ['slug' => $m[1]]).($m[2] ?? '').')',
            $markdown,
        );

        return $this->converter()->convert($markdown)->getContent();
    }

    /**
     * Identifiants des titres d'un chapitre (pour vérifier les ancres des liens et des « ? »).
     *
     * @return list<string>
     */
    public function anchors(HelpChapter $chapter, string $language = self::LANGUAGE): array
    {
        preg_match_all('/<h[2-6] id="([^"]+)"/', $this->html($chapter, $language), $m);

        return $m[1];
    }

    public function path(string $slug, string $language = self::LANGUAGE): string
    {
        return $this->directory.'/'.$language.'/'.$slug.'.md';
    }

    /**
     * @return list<HelpChapter>
     */
    private function all(): array
    {
        if (null === $this->chapters) {
            /** @var array{chapters: list<array{slug: string, audience: string, title: array<string, string>}>} $index */
            $index = Yaml::parseFile($this->directory.'/guide.yaml');
            $this->chapters = array_map(
                static fn (array $c): HelpChapter => new HelpChapter($c['slug'], $c['title'][self::LANGUAGE], 'admin' === $c['audience']),
                $index['chapters'],
            );
        }

        return $this->chapters;
    }

    private function converter(): MarkdownConverter
    {
        if (!$this->converter instanceof MarkdownConverter) {
            $environment = new Environment([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
                'heading_permalink' => ['insert' => 'none', 'apply_id_to_heading' => true, 'id_prefix' => '', 'fragment_prefix' => '', 'min_heading_level' => 2],
                'external_link' => ['internal_hosts' => [], 'open_in_new_window' => true, 'nofollow' => 'external', 'noopener' => 'external', 'noreferrer' => ''],
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new TableExtension());
            $environment->addExtension(new HeadingPermalinkExtension());
            $environment->addExtension(new ExternalLinkExtension());
            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter;
    }
}
