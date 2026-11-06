<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Media\Application\StoryComposer;
use App\Tests\AppWebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class StoryTest extends AppWebTestCase
{
    use ClockSensitiveTrait;

    public function testAStoryAppearsInTheFollowersBarAndIsMarkedAsSeen(): void
    {
        self::mockTime('2026-11-12 10:00:00');
        $camille = $this->register('camille');
        $story = $this->publish($camille->getAccount(), 'Café et brume');

        $this->login($this->register('hugo'));
        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Suivre')->form());

        $crawler = $this->client->request('GET', '/');
        self::assertSelectorExists('.stories a[href="/stories?depuis=camille"] .ring:not(.seen)');

        $crawler = $this->client->request('GET', '/stories?depuis=camille');
        self::assertResponseIsSuccessful();
        $data = $this->viewerData($crawler->filter('[data-controller="story-viewer"]')->attr('data-story-viewer-data-value'));
        self::assertSame('camille', $data['groups'][0]['username']);
        self::assertSame('Café et brume', $data['groups'][0]['stories'][0]['text']);
        self::assertStringStartsWith('/m/', $data['groups'][0]['stories'][0]['src']);

        $this->client->request('POST', $data['groups'][0]['stories'][0]['seenUrl'], ['_token' => $data['viewToken']]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(1, $this->reload($story)->getViewsCount());

        $this->client->request('GET', '/');
        self::assertSelectorExists('.stories a[href="/stories?depuis=camille"] .ring.seen');
    }

    public function testAFollowersOnlyStoryIsHiddenFromOtherVisitors(): void
    {
        $camille = $this->register('camille');
        $this->publish($camille->getAccount(), null, Visibility::Followers);

        $this->client->request('GET', '/@camille/stories');
        self::assertResponseStatusCodeSame(404);

        $hugo = $this->register('hugo');
        self::getContainer()->get(\App\Core\Application\Social\SocialActions::class)->follow($hugo->getAccount(), $camille->getAccount());
        $this->login($hugo);
        $this->client->request('GET', '/@camille/stories');
        self::assertResponseIsSuccessful();
    }

    public function testAnExpiredStoryMovesToTheArchiveAndCanBePinned(): void
    {
        self::mockTime('2026-11-12 10:00:00');
        $camille = $this->register('camille');
        $this->publish($camille->getAccount(), 'Lac Blanc');
        $hugo = $this->register('hugo');
        self::getContainer()->get(\App\Core\Application\Social\SocialActions::class)->follow($hugo->getAccount(), $camille->getAccount());

        self::mockTime('2026-11-13 10:30:00');

        $this->login($hugo);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.stories a[href="/stories?depuis=camille"]', 'Une story expirée quitte la barre.');
        $this->client->request('GET', '/@camille/stories');
        self::assertResponseStatusCodeSame(404);

        $this->login($camille);
        $crawler = $this->client->request('GET', '/stories/mes-stories');
        self::assertSelectorTextContains('main', 'Archives');
        self::assertSelectorCount(1, '.story-tile');
        $form = $crawler->selectButton('Mettre à la une')->form(['highlight' => '__new__', 'title' => 'Montagne']);
        $this->client->submit($form);
        self::assertResponseRedirects('/stories/mes-stories');

        $this->client->request('GET', '/deconnexion');
        $crawler = $this->client->request('GET', '/@camille');
        self::assertSelectorTextContains('.hls', 'Montagne');
        $this->client->request('GET', (string) $crawler->filter('.hls a.hl')->attr('href'));
        self::assertResponseIsSuccessful();
    }

    public function testAnArchivedStoryOpensInTheViewerForItsOwnerOnly(): void
    {
        self::mockTime('2026-11-10 10:00:00');
        $camille = $this->register('camille');
        $first = $this->publish($camille->getAccount(), 'Première');
        self::mockTime('2026-11-11 10:00:00');
        $second = $this->publish($camille->getAccount(), 'Seconde');
        self::mockTime('2026-11-13 10:30:00');

        $this->login($camille);
        $crawler = $this->client->request('GET', '/stories/mes-stories');
        self::assertSelectorCount(2, '.story-tile a.th[href^="/stories/archives/"]');
        $this->client->click($crawler->filter('a.th[href="/stories/archives/'.$second->getPublicId().'"]')->link());
        self::assertResponseIsSuccessful();

        $data = $this->viewerData($this->client->getCrawler()->filter('[data-story-viewer-data-value]')->attr('data-story-viewer-data-value'));
        $grid = $crawler->filter('.story-tile a.th')->each(static fn ($link): string => basename((string) $link->attr('href')));
        self::assertSame($grid, array_column($data['groups'][0]['stories'], 'id'), 'Même ordre que la grille des archives.');
        self::assertSame(['Seconde', 'Première'], array_column($data['groups'][0]['stories'], 'text'));
        self::assertSame(0, $data['groups'][0]['start'], 'La visionneuse s’ouvre sur la story choisie.');
        self::assertSame('Archives', $data['highlight']);
        self::assertSame('/stories/mes-stories', $data['closeUrl']);

        $this->login($this->register('hugo'));
        $this->client->request('GET', '/stories/archives/'.$first->getPublicId());
        self::assertResponseStatusCodeSame(404, 'Les archives ne sont visibles que par leur titulaire.');
    }

    public function testPublishStoriesFromTheFormIntoANewHighlight(): void
    {
        $this->login($this->register('camille'));

        $crawler = $this->client->request('GET', '/stories/nouvelle');
        /** @var array{compose_story: array<string, mixed>} $values */
        $values = $crawler->selectButton('Partager la story')->form()->getPhpValues();
        $values['compose_story']['text'] = 'Week-end à Chamonix';
        $values['compose_story']['audience'] = 'public';
        $values['compose_story']['highlight'] = '__new__';
        $values['compose_story']['highlightTitle'] = 'Chamonix';
        $this->client->request('POST', '/stories/nouvelle', $values, ['compose_story' => ['media' => [$this->jpeg(1080, 1920), $this->jpeg(1080, 1920)]]]);

        self::assertResponseRedirects('/');
        $stories = self::getContainer()->get(StoryRepository::class)->findBy([], ['createdAt' => 'ASC']);
        self::assertCount(2, $stories);
        self::assertSame('Week-end à Chamonix', $stories[0]->getText());
        self::assertNull($stories[1]->getText());
        self::assertSame('Chamonix', $stories[0]->getHighlight()?->getTitle());
        self::assertSame($stories[0]->getHighlight(), $stories[1]->getHighlight());

        $this->client->followRedirect();
        self::assertSelectorExists('.stories a[href="/stories?depuis=camille"]');
    }

    public function testDeletingAStoryRemovesItsFiles(): void
    {
        $camille = $this->register('camille');
        $story = $this->publish($camille->getAccount(), null);
        $original = (string) $story->getMedia()?->getOriginalPath();
        $this->login($camille);

        $crawler = $this->client->request('GET', '/stories/mes-stories');
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());

        self::assertResponseRedirects('/stories/mes-stories');
        self::assertSame([], self::getContainer()->get(StoryRepository::class)->findAll());
        self::assertFalse(self::getContainer()->get('originals.storage')->fileExists($original));
    }

    private function publish(Account $author, ?string $text, Visibility $audience = Visibility::Public): Story
    {
        /** @var StoryComposer $composer */
        $composer = self::getContainer()->get(StoryComposer::class);

        return $composer->publish($author, [$this->jpeg(1080, 1920)], $text, $audience)[0];
    }

    private function reload(Story $story): Story
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(StoryRepository::class)->findByPublicId($story->getPublicId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * @return array{groups: list<array{username: string, start: int, stories: list<array{id: string, text: ?string, src: string, seenUrl: string}>}>, viewToken: string, highlight: ?string, closeUrl: string}
     */
    private function viewerData(?string $json): array
    {
        self::assertNotNull($json);

        /** @var array{groups: list<array{username: string, start: int, stories: list<array{id: string, text: ?string, src: string, seenUrl: string}>}>, viewToken: string, highlight: ?string, closeUrl: string} $data */
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }
}
