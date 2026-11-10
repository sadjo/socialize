<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Music\MusicTrack;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\MusicTrackRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Tests\AppWebTestCase;
use League\Flysystem\FilesystemOperator;

final class MusicTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeOpenverse::reset();
    }

    public function testSearchingFreeMusicIsReservedToSignedInAccounts(): void
    {
        $this->client->request('GET', '/musique/recherche?q=piano');
        self::assertResponseRedirects('/connexion');

        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/publier');
        self::assertSelectorExists('[data-controller="music-picker"] input[name="compose_post[music][track]"]');
        self::assertSelectorExists('[data-music-picker-search-url-value="/musique/recherche"]');

        $this->client->request('GET', '/musique/recherche?q=piano');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.music-list', 'Piano du matin');
        self::assertSelectorTextContains('.music-list', 'Lina Morel · 1:15 · CC BY · classical, piano');
        self::assertSelectorTextContains('.music-list', 'Route 66');
        self::assertSelectorTextNotContains('.music-list', 'Sans fichier', 'Un morceau sans fichier téléchargeable n’est pas proposé.');
        self::assertSelectorExists(\sprintf('button[data-music-picker-id-param="%s"]', FakeOpenverse::PIANO));
        self::assertStringContainsString('category=music', FakeOpenverse::$requests[0]);

        $this->client->request('GET', '/musique/recherche?q=introuvable');
        self::assertSelectorTextContains('p.small', 'Aucune musique trouvée pour « introuvable »');

        FakeOpenverse::$down = true;
        $this->client->request('GET', '/musique/recherche?q=openverse-en-panne');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.field-errors', 'Openverse est injoignable');

        $this->client->request('GET', '/musique/recherche?q='.str_repeat('a', 101));
        self::assertResponseStatusCodeSame(400);
        unset($crawler);
    }

    public function testChoosingATrackCopiesItOnceOnTheInstance(): void
    {
        $this->login($this->register('camille'));
        $token = $this->chooseToken();

        $this->client->request('POST', '/musique/choisir/'.FakeOpenverse::PIANO, server: ['HTTP_X_CSRF_TOKEN' => 'mauvais-jeton']);
        self::assertResponseStatusCodeSame(403);

        $first = $this->choose(FakeOpenverse::PIANO, $token);
        self::assertSame('Piano du matin', $first['title']);
        self::assertSame('Lina Morel', $first['artist']);
        self::assertSame('CC BY 4.0', $first['license']);
        self::assertSame(40, $first['duration'], 'Durée lue dans le fichier, pas celle annoncée par Openverse.');
        self::assertMatchesRegularExpression('#^/m/music/[1-9A-HJ-NP-Za-km-z]+\.m4a$#', $first['src']);

        $track = self::getContainer()->get(MusicTrackRepository::class)->findByPublicId($first['id']);
        self::assertInstanceOf(MusicTrack::class, $track);
        self::assertSame('https://www.jamendo.com/track/0c0a8a9b', $track->getSourceUrl());
        self::assertTrue($this->derivatives()->fileExists($track->getPath()));

        $downloads = \count(array_filter(FakeOpenverse::$requests, static fn (string $url): bool => str_starts_with($url, FakeOpenverse::FILES)));
        $second = $this->choose(FakeOpenverse::PIANO, $token);
        self::assertSame($first['id'], $second['id'], 'Un morceau déjà copié est réutilisé.');
        self::assertCount($downloads, array_filter(FakeOpenverse::$requests, static fn (string $url): bool => str_starts_with($url, FakeOpenverse::FILES)));

        $this->client->request('POST', '/musique/choisir/'.FakeOpenverse::NO_FILE, server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Ce morceau ne peut pas être téléchargé.'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testAPostPlaysItsMusicAndCreditsIt(): void
    {
        $this->login($this->register('camille'));
        $track = $this->choose(FakeOpenverse::PIANO, $this->chooseToken());

        $crawler = $this->client->request('GET', '/publier');
        /** @var array{compose_post: array<string, mixed>} $values */
        $values = $crawler->selectButton('Publier')->form()->getPhpValues();
        $values['compose_post']['caption'] = 'Matin calme';
        $values['compose_post']['visibility'] = 'public';
        // Au-delà de la fin du morceau (40 s) : le début est ramené pour garder un extrait de 30 s.
        $values['compose_post']['music'] = ['track' => $track['id'], 'start' => '25'];
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg()]]]);
        self::assertResponseRedirects();

        $post = $this->onlyPost();
        self::assertSame('Piano du matin', $post->getMusic()?->getTitle());
        self::assertSame(10, $post->getMusicStart());

        $this->client->followRedirect();
        self::assertSelectorExists('.media[data-controller~="music"][data-music-start-value="10"][data-music-excerpt-value="30"]');
        self::assertSelectorExists(\sprintf('.media audio.post-music[src="%s"]', $track['src']));
        self::assertSelectorExists('.media .music-snd[aria-pressed="false"]');
        self::assertSelectorTextContains('.music-credit', 'Piano du matin · Lina Morel');
        self::assertSelectorTextContains('.music-credit', 'CC BY 4.0');
        self::assertSelectorExists('.music-credit a[href="https://creativecommons.org/licenses/by/4.0/"]');

        // Version fédérée : mention de la musique, que les autres serveurs ne jouent pas.
        $this->client->request('GET', '/p/'.$post->getPublicId(), server: ['HTTP_ACCEPT' => 'application/activity+json']);
        /** @var array{content: string} $note */
        $note = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Matin calme', $note['content']);
        self::assertStringContainsString('🎵 <a href="https://www.jamendo.com/track/0c0a8a9b" rel="nofollow noopener" target="_blank">Piano du matin – Lina Morel</a> (CC BY 4.0)', $note['content']);
    }

    public function testEditingAPostChangesOrRemovesItsMusic(): void
    {
        $this->login($this->register('camille'));
        $token = $this->chooseToken();
        $piano = $this->choose(FakeOpenverse::PIANO, $token);
        $rock = $this->choose(FakeOpenverse::ROCK, $token);

        $crawler = $this->client->request('GET', '/publier');
        /** @var array{compose_post: array<string, mixed>} $values */
        $values = $crawler->selectButton('Publier')->form()->getPhpValues();
        $values['compose_post']['music'] = ['track' => $piano['id'], 'start' => '0'];
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg()]]]);
        $post = $this->onlyPost();

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        self::assertSelectorTextContains('.music-chosen', 'Piano du matin');
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        self::assertSame($piano['id'], $form->getValues()['edit_post[music][track]'] ?? null, 'La musique actuelle est préremplie.');

        $this->client->submit($form, ['edit_post[music][track]' => $rock['id'], 'edit_post[music][start]' => '5']);
        self::assertResponseRedirects();
        $post = $this->onlyPost();
        self::assertSame('Route 66', $post->getMusic()?->getTitle());
        self::assertSame(5, $post->getMusicStart());

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(), ['edit_post[music][track]' => '', 'edit_post[music][start]' => '']);
        self::assertResponseRedirects();
        self::assertNull($this->onlyPost()->getMusic());

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(), ['edit_post[music][track]' => '1CgzyA4uEynS7BD47o9fsu']);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('.form-errors', 'La musique choisie est introuvable');
    }

    public function testAStoryPlaysItsMusicInTheViewer(): void
    {
        $this->login($this->register('camille'));
        $track = $this->choose(FakeOpenverse::ROCK, $this->chooseToken());

        $crawler = $this->client->request('GET', '/stories/nouvelle');
        /** @var array{compose_story: array<string, mixed>} $values */
        $values = $crawler->selectButton('Partager la story')->form()->getPhpValues();
        $values['compose_story']['audience'] = 'public';
        $values['compose_story']['music'] = ['track' => $track['id'], 'start' => '4'];
        $this->client->request('POST', '/stories/nouvelle', $values, ['compose_story' => ['media' => [$this->jpeg(1080, 1920)]]]);
        self::assertResponseRedirects('/');

        $story = self::getContainer()->get(StoryRepository::class)->findAll()[0];
        self::assertSame('Route 66', $story->getMusic()?->getTitle());

        $crawler = $this->client->request('GET', '/@camille/stories');
        self::assertResponseIsSuccessful();
        /** @var array{groups: list<array{stories: list<array{duration: float, music: array{src: string, start: int, excerpt: int, credit: string}}>}>} $data */
        $data = json_decode((string) $crawler->filter('[data-story-viewer-data-value]')->attr('data-story-viewer-data-value'), true, flags: \JSON_THROW_ON_ERROR);
        $story = $data['groups'][0]['stories'][0];
        self::assertSame(['src' => $track['src'], 'start' => 4, 'excerpt' => 30, 'credit' => 'Route 66 · The Testers · CC BY-SA 3.0'], $story['music']);
        self::assertEqualsWithDelta(15.0, $story['duration'], 0.01, 'Une photo avec musique reste affichée plus longtemps.');
    }

    private function chooseToken(): string
    {
        $crawler = $this->client->request('GET', '/publier');

        return (string) $crawler->filter('[data-music-picker-token-value]')->attr('data-music-picker-token-value');
    }

    /**
     * @return array{id: string, title: string, artist: string, license: string, duration: int, src: string}
     */
    private function choose(string $openverseId, string $token): array
    {
        $this->client->request('POST', '/musique/choisir/'.$openverseId, server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();

        /** @var array{id: string, title: string, artist: string, license: string, duration: int, src: string} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function onlyPost(): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $posts = self::getContainer()->get(PostRepository::class)->findAll();
        self::assertCount(1, $posts);

        return $posts[0];
    }

    private function derivatives(): FilesystemOperator
    {
        /** @var FilesystemOperator $storage */
        $storage = self::getContainer()->get('derivatives.storage');

        return $storage;
    }
}
