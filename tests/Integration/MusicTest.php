<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Music\MusicTrack;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\MusicTrackRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Integration\Openverse\Infrastructure\OpenverseClient;
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
        $this->client->request('GET', '/publier');
        self::assertSelectorExists('[data-controller="music-picker"] input[name="compose_post[music][track]"]');
        self::assertSelectorExists('[data-music-picker-search-url-value="/musique/recherche"]');
        self::assertSelectorExists('.music-picker button.music-open[data-action="music-picker#open"]', 'Bureau : bouton qui ouvre la fenêtre de recherche.');
        self::assertSelectorExists('.music-picker dialog.music-modal [data-music-picker-target="dialogBody"]');
        self::assertSelectorExists('.music-picker > .music-search input[type="search"]', 'Mobile : la recherche est dans la page.');

        $this->client->request('GET', '/musique/recherche?q=piano');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.music-list', 'Piano du matin');
        self::assertSelectorTextContains('.music-list', 'Lina Morel · 1:15 · CC BY 4.0 · classical, piano');
        self::assertSelectorTextContains('.music-list', 'Route 66');
        self::assertSelectorTextNotContains('.music-list', 'Sans fichier', 'Un morceau sans fichier téléchargeable n’est pas proposé.');
        self::assertSelectorExists(\sprintf('button[data-action="music-picker#listen"][data-music-picker-id-param="%s"]', FakeOpenverse::PIANO));
        self::assertSelectorExists(\sprintf('button[data-action="music-picker#choose"][data-music-picker-id-param="%s"][data-music-picker-title-param="Piano du matin"][data-music-picker-duration-param="75"]', FakeOpenverse::PIANO));
        self::assertStringContainsString('category=music', FakeOpenverse::$requests[0]);
        self::assertSelectorExists(\sprintf('.music-list .music-thumb img[src="/musique/vignette/%s"]', FakeOpenverse::PIANO), 'Vignette relayée par le serveur.');
        self::assertSelectorCount(1, '.music-list .music-thumb img', 'Pas d’image pour un morceau sans pochette : l’icône reste.');
        self::assertSelectorCount(2, '.music-list .music-thumb svg');

        $this->client->request('GET', '/musique/vignette/'.FakeOpenverse::PIANO);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        self::assertStringStartsWith("\xFF\xD8", $this->client->getInternalResponse()->getContent());
        $this->client->request('GET', '/musique/vignette/'.FakeOpenverse::ROCK);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/musique/recherche?q=introuvable');
        self::assertSelectorTextContains('p.small', 'Aucune musique trouvée pour « introuvable »');

        FakeOpenverse::$down = true;
        $this->client->request('GET', '/musique/recherche?q=openverse-en-panne');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.field-errors', 'Openverse est injoignable');

        $this->client->request('GET', '/musique/recherche?q='.str_repeat('a', 101));
        self::assertResponseStatusCodeSame(400);
    }

    public function testFavoritesAreShownBeforeSearchingAndBelongToTheirAccount(): void
    {
        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/publier');
        self::assertSelectorExists('[data-music-picker-favorites-url-value="/musique/favoris"]');
        $token = (string) $crawler->filter('[data-music-picker-token-value]')->attr('data-music-picker-token-value');

        $this->client->request('GET', '/musique/favoris');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.music-fav-title', 'Vos favoris');
        self::assertSelectorTextContains('p.small', 'Aucun favori pour l’instant');

        $this->client->request('POST', '/musique/favoris/'.FakeOpenverse::PIANO, server: ['HTTP_X_CSRF_TOKEN' => 'mauvais-jeton']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['favorite' => true], $this->favorite(FakeOpenverse::PIANO, $token));
        self::assertSame(['favorite' => true], $this->favorite(FakeOpenverse::ROCK, $token));

        $this->client->request('GET', '/musique/favoris');
        self::assertSelectorCount(2, '.music-list li');
        self::assertSelectorTextContains('.music-list li:first-child', 'Route 66', 'Le plus récent en premier.');
        self::assertSelectorTextContains('.music-list li:last-child', 'Piano du matin');
        self::assertSelectorTextContains('.music-list li:last-child', 'Lina Morel · 1:15 · CC BY 4.0 · classical, piano');
        self::assertSelectorCount(2, '.music-list .music-fav[aria-pressed="true"]');
        self::assertSelectorExists(\sprintf('.music-list .music-thumb img[src="/musique/vignette/%s"]', FakeOpenverse::PIANO));
        self::assertSelectorExists(\sprintf('button[data-action="music-picker#choose"][data-music-picker-id-param="%s"]', FakeOpenverse::PIANO));

        $this->client->request('GET', '/musique/recherche?q=piano');
        self::assertSelectorCount(2, '.music-list .music-fav[aria-pressed="true"]', 'Les favoris sont marqués dans les résultats.');

        self::assertSame(['favorite' => false], $this->favorite(FakeOpenverse::PIANO, $token), 'Un second clic retire le favori.');
        $this->client->request('GET', '/musique/favoris');
        self::assertSelectorCount(1, '.music-list li');

        $this->client->request('POST', '/musique/favoris/'.FakeOpenverse::NO_FILE, server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsUnprocessable();
        self::assertSame([], self::getContainer()->get(MusicTrackRepository::class)->findAll(), 'Un favori ne copie pas le morceau.');

        $this->login($this->register('hugo'));
        $this->client->request('GET', '/musique/favoris');
        self::assertSelectorCount(0, '.music-list li', 'Chaque compte a ses favoris.');
    }

    public function testSearchingKeepsEachTrackSoListeningDoesNotAskOpenverseAgain(): void
    {
        $openverse = self::getContainer()->get(OpenverseClient::class);
        $openverse->search('piano');
        self::assertSame('Piano du matin', $openverse->track(FakeOpenverse::PIANO)->title);
        self::assertSame('Route 66', $openverse->track(strtoupper(FakeOpenverse::ROCK))->title);
        self::assertSame([], array_values(array_filter(FakeOpenverse::$requests, static fn (string $url): bool => str_contains($url, '/v1/audio/'.FakeOpenverse::PIANO.'/'))), 'Fiche reprise de la recherche, sans nouvel appel à Openverse.');
    }

    public function testListeningRelaysTheFileWithoutStoringIt(): void
    {
        $this->client->request('GET', '/musique/ecouter/'.FakeOpenverse::PIANO);
        self::assertResponseRedirects('/connexion');

        $this->login($this->register('camille'));
        $this->client->request('GET', '/musique/ecouter/'.FakeOpenverse::PIANO, server: ['HTTP_RANGE' => 'bytes=0-99']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Type', 'audio/mp4');
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes');
        self::assertMatchesRegularExpression('#^bytes 0-99/\d+$#', (string) $this->client->getResponse()->headers->get('Content-Range'));
        self::assertSame(100, \strlen($this->client->getInternalResponse()->getContent()));

        $this->client->request('GET', '/musique/ecouter/'.FakeOpenverse::PIANO);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(10_000, \strlen($this->client->getInternalResponse()->getContent()));

        self::assertSame([], self::getContainer()->get(MusicTrackRepository::class)->findAll(), 'La pré-écoute ne copie rien sur l’instance.');
        self::assertSame([], $this->derivatives()->listContents('music')->toArray());

        $this->client->request('GET', '/musique/ecouter/'.FakeOpenverse::NO_FILE);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPostPlaysItsMusicAndCreditsIt(): void
    {
        $this->login($this->register('camille'));
        // Au-delà de la fin du morceau (40 s) : le début est ramené pour garder un extrait de 30 s.
        $this->publish(FakeOpenverse::PIANO, '25', 'Matin calme');
        self::assertResponseRedirects();

        $post = $this->onlyPost();
        $track = $post->getMusic();
        self::assertInstanceOf(MusicTrack::class, $track);
        self::assertSame('Piano du matin', $track->getTitle());
        self::assertSame(40, $track->getDuration(), 'Durée lue dans le fichier, pas celle annoncée par Openverse.');
        self::assertSame('https://www.jamendo.com/track/0c0a8a9b', $track->getSourceUrl());
        self::assertTrue($this->derivatives()->fileExists($track->getPath()), 'Le morceau est copié sur l’instance à la publication.');
        self::assertSame(10, $post->getMusicStart());

        $this->client->followRedirect();
        self::assertSelectorExists('.media[data-controller~="music"][data-music-start-value="10"][data-music-excerpt-value="30"]');
        self::assertSelectorExists(\sprintf('.media audio.post-music[src="/m/%s"]', $track->getPath()));
        self::assertSelectorExists('.media .music-snd[aria-pressed="false"]');
        self::assertSelectorTextContains('.music-credit', 'Piano du matin · Lina Morel');
        self::assertSelectorTextContains('.music-credit', 'CC BY 4.0');
        self::assertSelectorExists('.music-credit a[href="https://creativecommons.org/licenses/by/4.0/"]');

        // Un morceau déjà copié est servi par sa copie, sans passer par le fournisseur.
        $this->client->request('GET', '/musique/ecouter/'.FakeOpenverse::PIANO);
        self::assertResponseRedirects('/m/'.$track->getPath());

        // Version fédérée : mention de la musique, que les autres serveurs ne jouent pas.
        $this->client->request('GET', '/p/'.$post->getPublicId(), server: ['HTTP_ACCEPT' => 'application/activity+json']);
        /** @var array{content: string} $note */
        $note = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Matin calme', $note['content']);
        self::assertStringContainsString('🎵 <a href="https://www.jamendo.com/track/0c0a8a9b" rel="nofollow noopener" target="_blank">Piano du matin – Lina Morel</a> (CC BY 4.0)', $note['content']);
    }

    public function testATrackIsCopiedOnceAndAnUnusableOneIsRefused(): void
    {
        $this->login($this->register('camille'));
        $this->publish(FakeOpenverse::ROCK, '0');
        $this->publish(FakeOpenverse::ROCK, '30');

        self::assertCount(1, self::getContainer()->get(MusicTrackRepository::class)->findAll());
        self::assertCount(1, array_filter(FakeOpenverse::$requests, static fn (string $url): bool => str_starts_with($url, FakeOpenverse::FILES)), 'Un seul téléchargement.');

        $this->publish(FakeOpenverse::NO_FILE, '0');
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('.form-errors', 'Ce morceau ne peut pas être téléchargé.');
        self::assertSelectorNotExists('.music-chosen[hidden]', 'Le morceau choisi reste affiché.');
        self::assertSelectorTextContains('.music-chosen', 'Sans fichier');
    }

    public function testEditingAPostChangesOrRemovesItsMusic(): void
    {
        $this->login($this->register('camille'));
        $this->publish(FakeOpenverse::PIANO, '0');
        $post = $this->onlyPost();

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        self::assertSelectorTextContains('.music-chosen', 'Piano du matin');
        self::assertSelectorExists(\sprintf('.music-chosen audio[src="/musique/ecouter/%s"]', FakeOpenverse::PIANO));
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        self::assertSame(FakeOpenverse::PIANO, $form->getValues()['edit_post[music][track]'] ?? null, 'La musique actuelle est préremplie.');

        $this->client->submit($form, ['edit_post[music][track]' => FakeOpenverse::ROCK, 'edit_post[music][start]' => '5']);
        self::assertResponseRedirects();
        $post = $this->onlyPost();
        self::assertSame('Route 66', $post->getMusic()?->getTitle());
        self::assertSame(5, $post->getMusicStart());

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(), ['edit_post[music][track]' => '', 'edit_post[music][start]' => '']);
        self::assertResponseRedirects();
        self::assertNull($this->onlyPost()->getMusic());

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(), ['edit_post[music][track]' => '7d0e2a1c-0000-4000-8000-000000000999']);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('.form-errors', 'Morceau introuvable sur Openverse.');

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(), ['edit_post[music][track]' => 'pas-un-identifiant']);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('main', 'La musique choisie est introuvable');
    }

    public function testAStoryPlaysItsMusicInTheViewer(): void
    {
        $this->login($this->register('camille'));

        $crawler = $this->client->request('GET', '/stories/nouvelle');
        /** @var array{compose_story: array<string, mixed>} $values */
        $values = $crawler->selectButton('Partager la story')->form()->getPhpValues();
        $values['compose_story']['audience'] = 'public';
        $values['compose_story']['music'] = ['track' => FakeOpenverse::ROCK, 'start' => '4'];
        $this->client->request('POST', '/stories/nouvelle', $values, ['compose_story' => ['media' => [$this->jpeg(1080, 1920)]]]);
        self::assertResponseRedirects('/');

        $story = self::getContainer()->get(StoryRepository::class)->findAll()[0];
        $track = $story->getMusic();
        self::assertInstanceOf(MusicTrack::class, $track);
        self::assertSame('Route 66', $track->getTitle());

        $crawler = $this->client->request('GET', '/@camille/stories');
        self::assertResponseIsSuccessful();
        /** @var array{groups: list<array{stories: list<array{duration: float, music: array{src: string, start: int, excerpt: int, credit: string}}>}>} $data */
        $data = json_decode((string) $crawler->filter('[data-story-viewer-data-value]')->attr('data-story-viewer-data-value'), true, flags: \JSON_THROW_ON_ERROR);
        $story = $data['groups'][0]['stories'][0];
        self::assertSame(['src' => '/m/'.$track->getPath(), 'start' => 4, 'excerpt' => 30, 'credit' => 'Route 66 · The Testers · CC BY-SA 3.0'], $story['music']);
        self::assertEqualsWithDelta(15.0, $story['duration'], 0.01, 'Une photo avec musique reste affichée plus longtemps.');
    }

    private function publish(string $openverseId, string $start, string $caption = ''): void
    {
        $crawler = $this->client->request('GET', '/publier');
        /** @var array{compose_post: array<string, mixed>} $values */
        $values = $crawler->selectButton('Publier')->form()->getPhpValues();
        $values['compose_post']['caption'] = $caption;
        $values['compose_post']['visibility'] = 'public';
        $values['compose_post']['music'] = ['track' => $openverseId, 'start' => $start];
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg()]]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function favorite(string $openverseId, string $token): array
    {
        $this->client->request('POST', '/musique/favoris/'.$openverseId, server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
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
