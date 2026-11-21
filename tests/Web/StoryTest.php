<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Story\StoryActions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\HighlightRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Media\Application\StoryComposer;
use App\Tests\AppWebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

final class StoryTest extends AppWebTestCase
{
    use ClockSensitiveTrait;

    public function testTheStoriesBarIsHiddenWhenThereIsNoStoryToSee(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $this->login($hugo);
        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Suivre')->form());

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.stories', 'Aucune story à voir : pas de barre.');

        // Une story d'un compte suivi : la barre paraît, avec le bouton d'ajout.
        $this->publish($this->fresh($camille->getAccount()), 'Matin');
        $this->client->request('GET', '/');
        self::assertSelectorExists('.stories a[href="/stories?depuis=camille"]');
        self::assertSelectorExists('.stories a[href="/stories/nouvelle"]');

        // Sa propre story seule suffit aussi.
        $lina = $this->register('lina');
        $this->login($lina);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.stories');
        $this->publish($this->fresh($lina->getAccount()), 'Soir');
        $this->client->request('GET', '/');
        self::assertSelectorExists('.stories a[href="/stories?depuis=lina"]');
    }

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
        $this->tickStories($form);
        $this->client->submit($form);
        self::assertResponseRedirects('/stories/mes-stories');

        $this->client->request('GET', '/deconnexion');
        $crawler = $this->client->request('GET', '/@camille');
        self::assertSelectorTextContains('.hls', 'Montagne');
        $this->client->request('GET', (string) $crawler->filter('.hls a.hl')->attr('href'));
        self::assertResponseIsSuccessful();
    }

    public function testSeveralStoriesArePinnedAtOnceThenRemovedOneByOne(): void
    {
        self::mockTime('2026-11-10 10:00:00');
        $camille = $this->register('camille');
        $first = $this->publish($camille->getAccount(), 'Première');
        self::mockTime('2026-11-11 10:00:00');
        $second = $this->publish($camille->getAccount(), 'Seconde');
        // Story en cours réservée à des groupes : elle se gère aussi depuis « Mes stories ».
        self::mockTime('2026-11-13 10:00:00');
        $third = $this->publish($camille->getAccount(), 'Pour la famille', Visibility::Groups);
        self::mockTime('2026-11-13 10:30:00');

        $this->login($camille);
        $crawler = $this->client->request('GET', '/stories/mes-stories');
        self::assertSelectorCount(3, '.story-tile input[name="stories[]"]');
        $form = $crawler->selectButton('Mettre à la une')->form(['highlight' => '__new__', 'title' => 'Montagne']);
        $this->tickStories($form);
        $this->client->submit($form);
        self::assertResponseRedirects('/stories/mes-stories');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash, main', '3 stories ajoutées à « Montagne »');

        $highlight = $this->reload($first)->getHighlight();
        self::assertNotNull($highlight);
        self::assertSame(['Première', 'Seconde', 'Pour la famille'], array_map(static fn (Story $s): ?string => $s->getText(), $highlight->getStories()->toArray()));

        // Retrait depuis la page de la story à la une : on y reste tant qu'elle a des stories.
        $crawler = $this->client->request('GET', '/a-la-une/'.$highlight->getPublicId().'/modifier');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '.story-tile');
        $this->client->submit($crawler->filter('form[action="/stories/'.$third->getPublicId().'/retirer-de-la-une"]')->form());
        self::assertResponseRedirects('/a-la-une/'.$highlight->getPublicId().'/modifier');
        self::assertNull($this->reload($third)->getHighlight());

        // Retrait depuis « Mes stories ».
        $crawler = $this->client->request('GET', '/stories/mes-stories');
        $this->client->submit($crawler->filter('form[action="/stories/'.$second->getPublicId().'/retirer-de-la-une"]')->form());
        self::assertResponseRedirects('/stories/mes-stories');

        // La dernière story retirée : la story à la une disparaît, on revient à « Mes stories ».
        $crawler = $this->client->request('GET', '/a-la-une/'.$highlight->getPublicId().'/modifier');
        $this->client->submit($crawler->filter('form[action="/stories/'.$first->getPublicId().'/retirer-de-la-une"]')->form());
        self::assertResponseRedirects('/stories/mes-stories');
        self::assertSame([], self::getContainer()->get(HighlightRepository::class)->findAll());
    }

    public function testPinningWithoutAnyTickedStoryIsRefused(): void
    {
        $camille = $this->register('camille');
        $this->publish($camille->getAccount(), 'Lac');
        $this->login($camille);
        $crawler = $this->client->request('GET', '/stories/mes-stories');
        $this->client->submit($crawler->selectButton('Mettre à la une')->form(['highlight' => '__new__', 'title' => 'Lac']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Cochez au moins une story');
        self::assertSame([], self::getContainer()->get(HighlightRepository::class)->findAll());
    }

    public function testAHighlightGetsANewTitleAndItsOwnCover(): void
    {
        $camille = $this->register('camille');
        $story = $this->publish($camille->getAccount(), 'Lac');
        $highlight = self::getContainer()->get(StoryActions::class)->pin($story, null, 'Lac');
        $this->login($camille);

        $crawler = $this->client->request('GET', '/a-la-une/'.$highlight->getPublicId().'/modifier');
        $form = $crawler->selectButton('Enregistrer')->form(['highlight[title]' => 'Montagne']);
        $values = $form->getPhpValues();
        $this->client->request('POST', $form->getUri(), $values, ['highlight' => ['media' => [$this->jpeg(1200, 800)]]]);
        self::assertResponseRedirects('/a-la-une/'.$highlight->getPublicId().'/modifier');

        $saved = $this->reload($story)->getHighlight();
        self::assertNotNull($saved);
        self::assertSame('Montagne', $saved->getTitle());
        $cover = (string) $saved->getCoverPath();
        self::assertStringStartsWith('highlights/', $cover);
        $storage = self::getContainer()->get('derivatives.storage');
        self::assertTrue($storage->fileExists($cover));

        $this->client->request('GET', '/@camille');
        self::assertSelectorExists('.hls a.hl img[src$="'.basename($cover).'"]');

        // Retour à la couverture automatique.
        $crawler = $this->client->request('GET', '/a-la-une/'.$highlight->getPublicId().'/modifier');
        $form = $crawler->selectButton('Enregistrer')->form(['highlight[removeCover]' => '1']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $saved = $this->reload($story)->getHighlight();
        self::assertNull($saved?->getCoverPath());
        self::assertFalse($storage->fileExists($cover), 'Le fichier de l’ancienne couverture est supprimé.');

        // Un autre compte n'y a pas accès.
        $this->login($this->register('hugo'));
        $this->client->request('GET', '/a-la-une/'.$highlight->getPublicId().'/modifier');
        self::assertResponseStatusCodeSame(404);
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

    /** Coche les cases des stories (sélection pour la mise à la une groupée). */
    private function tickStories(Form $form): void
    {
        foreach ($form->all() as $field) {
            if ($field instanceof ChoiceFormField && str_starts_with($field->getName(), 'stories[')) {
                $field->tick();
            }
        }
    }

    /** Compte rechargé dans le gestionnaire d'entités actuel (après des requêtes HTTP). */
    private function fresh(Account $account): Account
    {
        $fresh = self::getContainer()->get('doctrine')->getRepository(Account::class)->find($account->getId());
        self::assertInstanceOf(Account::class, $fresh);

        return $fresh;
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
