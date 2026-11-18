<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;
use App\Web\Help\HelpChapter;
use App\Web\Help\HelpGuide;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Aide (/aide) : guide d'utilisation lisible sans compte, chapitre d'administration réservé, et cohérence de la source
 * docs/guide (deux langues, liens entre chapitres, « ? » de l'interface).
 */
final class HelpTest extends AppWebTestCase
{
    public function testVisitorsReadTheGuideFromTheDiscreetLink(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $crawler = $this->client->click($crawler->filter('a.side-help')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Aide');
        self::assertSelectorTextNotContains('.help-toc', 'Administration');

        $this->client->click($crawler->selectLink('Publier')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Publier');
        self::assertSelectorExists('.help-body h2#visibilité');
        self::assertSelectorExists('.help-body table', 'Les tableaux sont rendus.');
        self::assertSelectorExists('.help-body a[href="/aide/groupes"]', 'Les liens entre chapitres mènent à l’aide.');
        self::assertSelectorExists('a[rel="next"][href="/aide/stories"]');
    }

    public function testTheAdministrationChapterIsReservedToAdministrators(): void
    {
        $admin = $this->register('camille');
        $this->client->request('GET', '/aide/administration');
        self::assertResponseStatusCodeSame(404);

        $this->login($this->register('hugo'));
        $this->client->request('GET', '/aide/administration');
        self::assertResponseStatusCodeSame(404);

        $this->login($admin);
        $this->client->request('GET', '/aide');
        self::assertSelectorTextContains('.help-toc', 'Administration');
        $this->client->request('GET', '/aide/administration');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.help-body h2#invitations');
    }

    public function testAnUnknownChapterIsNotFound(): void
    {
        $this->client->request('GET', '/aide/inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryChapterExistsInBothLanguages(): void
    {
        $directory = \dirname(__DIR__, 2).'/docs/guide';
        /** @var array{chapters: list<array{slug: string, audience: string, title: array{fr: string, en: string}}>} $index */
        $index = Yaml::parseFile($directory.'/guide.yaml');
        foreach ($index['chapters'] as $chapter) {
            foreach (['fr', 'en'] as $language) {
                $file = $directory.'/'.$language.'/'.$chapter['slug'].'.md';
                self::assertFileExists($file);
                self::assertStringStartsWith('# ', (string) file_get_contents($file), $file.' commence par son titre.');
            }
            self::assertContains($chapter['audience'], ['all', 'admin']);
        }
        foreach (['fr', 'en'] as $language) {
            self::assertCount(\count($index['chapters']), glob($directory.'/'.$language.'/*.md') ?: [], 'Aucun chapitre hors de guide.yaml ('.$language.').');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        yield 'français' => ['fr'];
        yield 'anglais' => ['en'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function testLinksBetweenChaptersPointToExistingHeadings(string $language): void
    {
        $guide = $this->guide();
        $anchors = [];
        foreach ($guide->chapters(true) as $chapter) {
            $anchors[$chapter->slug] = $guide->anchors($chapter, $language);
        }
        foreach ($guide->chapters(true) as $chapter) {
            preg_match_all('/\]\(([a-z0-9-]+)\.md(?:#([^)\s]+))?\)/', (string) file_get_contents($guide->path($chapter->slug, $language)), $links, \PREG_SET_ORDER);
            foreach ($links as $link) {
                self::assertArrayHasKey($link[1], $anchors, \sprintf('%s/%s.md : chapitre %s inconnu.', $language, $chapter->slug, $link[1]));
                if (isset($link[2])) {
                    self::assertContains($link[2], $anchors[$link[1]], \sprintf('%s/%s.md : ancre %s#%s introuvable.', $language, $chapter->slug, $link[1], $link[2]));
                }
            }
        }
        // Les ancres doivent être uniques dans tout le guide : le wiki assemble les chapitres en une seule page.
        $all = array_merge(...array_values($anchors));
        self::assertSame(array_values(array_unique($all)), $all, 'Titres en double dans le guide ('.$language.').');
    }

    public function testEveryHelpButtonOfTheInterfacePointsToAHeading(): void
    {
        $guide = $this->guide();
        $found = 0;
        foreach (new Finder()->files()->in(\dirname(__DIR__, 2).'/templates')->name('*.twig') as $template) {
            preg_match_all("/ui\\.help\\('([a-z0-9-]+)', (?:'([^']+)'|null)/", $template->getContents(), $calls, \PREG_SET_ORDER);
            foreach ($calls as $call) {
                ++$found;
                $chapter = $guide->chapter($call[1], true);
                self::assertInstanceOf(HelpChapter::class, $chapter, $template->getRelativePathname().' : chapitre '.$call[1].' inconnu.');
                if (isset($call[2])) {
                    self::assertContains($call[2], $guide->anchors($chapter), $template->getRelativePathname().' : ancre '.$call[1].'#'.$call[2].' introuvable.');
                }
            }
        }
        self::assertGreaterThan(10, $found);
    }

    private function guide(): HelpGuide
    {
        return self::getContainer()->get(HelpGuide::class);
    }
}
