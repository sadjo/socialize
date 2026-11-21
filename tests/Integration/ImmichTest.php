<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Application\Story\StoryActions;
use App\Core\Domain\Account\User;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\HighlightRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Integration\Immich\Application\ImmichConnections;
use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\Doctrine\ImmichConnectionRepository;
use App\Integration\Immich\Infrastructure\ImmichVault;
use App\Media\Application\ProcessVideo;
use App\Media\Application\StoryComposer;
use App\Tests\AppWebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ImmichTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeImmich::reset();
    }

    public function testConnectingChecksTheKeyAndStoresItEncrypted(): void
    {
        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/reglages/immich');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Tester la connexion')->form([
            'immich_settings[serverUrl]' => FakeImmich::SERVER,
            'immich_settings[apiKey]' => 'mauvaise-cle',
        ]));
        self::assertSelectorTextContains('.form-errors', 'Clé API refusée');
        self::assertNull($this->connection('camille'));

        $crawler = $this->client->request('GET', '/reglages/immich');
        $this->client->submit($crawler->selectButton('Tester la connexion')->form([
            'immich_settings[serverUrl]' => FakeImmich::SERVER.'/api/',
            'immich_settings[apiKey]' => FakeImmich::KEY,
            'immich_settings[addToAlbum]' => true,
        ]));
        self::assertResponseRedirects('/reglages/immich');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Immich connecté (Camille Test <camille@immich.test>, version 3.4.1) : 2 photos, 1 vidéo, 1 album.');
        self::assertSelectorTextContains('.zip', 'Connecté');
        self::assertStringNotContainsString(FakeImmich::KEY, (string) $this->client->getResponse()->getContent(), 'La clé n’est jamais réaffichée.');

        $connection = $this->connection('camille');
        self::assertInstanceOf(ImmichConnection::class, $connection);
        self::assertSame(FakeImmich::SERVER, $connection->getServerUrl(), 'Adresse sans « /api ».');
        self::assertStringNotContainsString(FakeImmich::KEY, $connection->getApiKeyEncrypted());
        self::assertSame(FakeImmich::KEY, self::getContainer()->get(ImmichVault::class)->decrypt($connection->getApiKeyEncrypted()));
        self::assertTrue($connection->addsToAlbum());

        // Sans nouvelle clé, celle enregistrée est gardée ; l'option album se décoche.
        $crawler = $this->client->request('GET', '/reglages/immich');
        $this->client->submit($crawler->selectButton('Tester et enregistrer')->form(['immich_settings[addToAlbum]' => false]));
        self::assertResponseRedirects('/reglages/immich');
        self::assertFalse($this->connection('camille')?->addsToAlbum());

        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->selectButton('Déconnecter Immich')->form());
        self::assertNull($this->connection('camille'));
    }

    public function testTheLibraryAndThumbnailsAreReservedToTheConnectedAccount(): void
    {
        $camille = $this->connected('camille');
        $this->login($camille);

        $this->client->request('GET', '/publier');
        self::assertSelectorExists('.src-tabs button[data-compose-source-param="immich"]');
        self::assertSelectorTextContains('#immich-album', 'Normandie (2)');
        self::assertSelectorExists('.imm-source input[type="date"][data-action*="filterLibrary"]');
        self::assertSelectorExists('.imm-source[data-controller="library-preview"]');

        $this->client->request('GET', '/immich/phototheque', server: ['HTTP_TURBO_FRAME' => 'immich-library']);
        self::assertSelectorExists('turbo-frame#immich-library:not([src])');
        self::assertSelectorCount(4, '.imm-tile', 'La vidéo cachée d’une photo live n’a pas de vignette à elle.');
        self::assertSelectorTextContains('.imm-day', 'Lundi 9 novembre 2026 · Honfleur, France');
        $video = '.imm-tile[data-preview-video="/immich/video/'.FakeImmich::VIDEO_LAC.'"]';
        self::assertSelectorExists($video.' .imm-pick .gi');
        self::assertSelectorTextContains($video.' .gi', '0:04');
        self::assertSelectorExists($video.' .imm-pick[data-compose-token-param="i-'.FakeImmich::VIDEO_LAC.'"][data-action="compose#toggleLibrary"]', 'Toucher la vignette la choisit.');
        $photo = '.imm-tile[data-preview-image="/immich/grand/'.FakeImmich::PHOTO_ETRETAT.'"]';
        self::assertSelectorExists($photo.'[data-preview-thumb="/immich/apercu/'.FakeImmich::PHOTO_ETRETAT.'"]:not([data-preview-video])');
        self::assertSelectorExists($photo.' .imm-zoom[data-action="library-preview#open"]', 'La loupe ouvre l’aperçu.');

        $this->client->request('GET', '/immich/phototheque?album='.FakeImmich::ALBUM_NORMANDIE);
        self::assertSelectorCount(2, '.imm-tile');

        // Aller au 8 novembre : les médias pris ce jour-là et avant, pas ceux du 9.
        $this->client->request('GET', '/immich/phototheque?date=2026-11-08');
        self::assertSelectorCount(3, '.imm-tile');
        self::assertSelectorTextContains('.imm-day', 'Dimanche 8 novembre 2026');
        self::assertSelectorNotExists('.imm-pick[data-compose-token-param="i-'.FakeImmich::PHOTO_HONFLEUR.'"]');
        $this->client->request('GET', '/immich/phototheque?date=2026-11-08&album='.FakeImmich::ALBUM_NORMANDIE);
        self::assertSelectorCount(1, '.imm-tile');
        $this->client->request('GET', '/immich/phototheque?date=2026-01-01');
        self::assertSelectorTextContains('turbo-frame', 'jusqu’au 1 janvier 2026');
        $this->client->request('GET', '/immich/phototheque?date=pas-une-date');
        self::assertSelectorCount(4, '.imm-tile', 'Une date invalide est ignorée.');

        $this->client->request('GET', '/immich/apercu/'.FakeImmich::PHOTO_ETRETAT);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        self::assertSame('thumbnail', FakeImmich::requestsTo('GET', '#/thumbnail$#')[0]['query']['size'] ?? null);

        // Aperçu : image en grand, et vidéo lue par plages (relayée telle quelle).
        $this->client->request('GET', '/immich/grand/'.FakeImmich::PHOTO_ETRETAT);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        self::assertSame('preview', array_last(FakeImmich::requestsTo('GET', '#/thumbnail$#'))['query']['size'] ?? null);
        $this->client->request('GET', '/immich/video/'.FakeImmich::VIDEO_LAC, server: ['HTTP_RANGE' => 'bytes=0-99']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Type', 'video/mp4');
        self::assertResponseHeaderSame('Content-Length', '100');
        self::assertMatchesRegularExpression('#^bytes 0-99/\d+$#', (string) $this->client->getResponse()->headers->get('Content-Range'));
        self::assertSame('bytes=0-99', array_last(FakeImmich::requestsTo('GET', '#/video/playback$#'))['range'] ?? null);
        $this->client->request('GET', '/immich/video/'.FakeImmich::VIDEO_LAC);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes');

        $this->login($this->register('hugo'));
        foreach (['/immich/apercu/', '/immich/grand/', '/immich/video/'] as $page) {
            $this->client->request('GET', $page.FakeImmich::PHOTO_ETRETAT);
            self::assertResponseStatusCodeSame(404, 'Sans connexion Immich, rien : '.$page);
        }
        $this->client->request('GET', '/publier');
        self::assertSelectorNotExists('.src-tabs');
    }

    public function testALivePhotoShowsItsStillAndOffersBothVersions(): void
    {
        $this->login($this->connected('camille', addToAlbum: true));

        // Immich liste la photo et sa courte vidéo (cachée, sans vignette) : seule la photo fixe a une vignette.
        $this->client->request('GET', '/immich/phototheque');
        self::assertSelectorNotExists('[data-compose-token-param="i-'.FakeImmich::LIVE_MOTION.'"]');
        $live = '.imm-tile[data-preview-thumb="/immich/apercu/'.FakeImmich::PHOTO_LIVE.'"]';
        self::assertSelectorExists($live.'[data-preview-live="/immich/video/'.FakeImmich::LIVE_MOTION.'"]:not([data-preview-video])', 'L’aperçu montre la photo fixe, animée par sa courte vidéo au survol ou à l’appui long.');
        self::assertSelectorTextContains($live.' .imm-pick .gi.live', 'LIVE');
        self::assertSelectorExists($live.' .imm-pick[data-compose-token-param="i-'.FakeImmich::PHOTO_LIVE.'"][data-compose-video-param="false"]', 'Toucher la vignette choisit la photo fixe.');
        self::assertSelectorExists($live.' .imm-pick-live[hidden][data-compose-token-param="i-'.FakeImmich::PHOTO_LIVE.'-live"][data-compose-video-param="true"][data-action="compose#toggleLibrary"]');
        self::assertStringContainsString('Photo live du', (string) $this->client->getCrawler()->filter($live)->attr('data-preview-label'));

        $this->client->request('GET', '/immich/video/'.FakeImmich::LIVE_MOTION);
        self::assertResponseIsSuccessful();

        // Version live : la courte vidéo, avec l'EXIF de la photo ; c'est la photo qui rejoint l'album.
        $post = $this->publish(['i-'.FakeImmich::PHOTO_LIVE.'-live'], ['i-'.FakeImmich::PHOTO_LIVE.'-live' => 'Le lac en mouvement']);
        $media = array_values($post->getMedia()->toArray());
        self::assertCount(1, $media);
        self::assertTrue($media[0]->isVideo());
        self::assertSame('Le lac en mouvement', $media[0]->getAltText());
        self::assertSame('Annecy', $media[0]->getExif()['immich-city'] ?? null);
        self::assertSame('/assets/'.FakeImmich::LIVE_MOTION.'/original', array_last(array_column(FakeImmich::requestsTo('GET', '#/original$#'), 'path')));

        // Photo fixe.
        $post = $this->publish(['i-'.FakeImmich::PHOTO_LIVE], []);
        $media = array_values($post->getMedia()->toArray());
        self::assertFalse($media[0]->isVideo());
        self::assertSame('/assets/'.FakeImmich::PHOTO_LIVE.'/original', array_last(array_column(FakeImmich::requestsTo('GET', '#/original$#'), 'path')));

        $album = array_find(FakeImmich::$albums, static fn (array $a): bool => 'Publié sur Socialize' === $a['name']);
        self::assertSame([FakeImmich::PHOTO_LIVE, FakeImmich::PHOTO_LIVE], $album['assets'] ?? null);

        // Une photo ordinaire n'a pas de version live.
        $crawler = $this->client->request('GET', '/publier');
        $values = $this->values($crawler->selectButton('Publier')->form()->getPhpValues());
        $values['compose_post']['order'] = 'i-'.FakeImmich::PHOTO_ETRETAT.'-live';
        $this->client->request('POST', '/publier', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors', 'n’est pas une photo live');
    }

    public function testAPostMixesImmichAndDeviceMediaInTheChosenOrder(): void
    {
        $camille = $this->connected('camille', addToAlbum: true);
        $this->login($camille);

        $post = $this->publish(['i-'.FakeImmich::PHOTO_ETRETAT, 'n-0', 'i-'.FakeImmich::VIDEO_LAC], ['i-'.FakeImmich::PHOTO_ETRETAT => 'Falaise au lever du soleil', 'n-0' => 'Photo du téléphone']);
        $media = array_values($post->getMedia()->toArray());
        self::assertCount(3, $media);
        self::assertSame('Falaise au lever du soleil', $media[0]->getAltText());
        self::assertSame('49.7072', $media[0]->getExif()['gps-latitude'] ?? null, 'Le GPS connu d’Immich est gardé dans l’original.');
        self::assertSame('XF16-55mm F2.8 R LM WR', $media[0]->getExif()['immich-lensModel'] ?? null);
        self::assertSame('Photo du téléphone', $media[1]->getAltText());
        self::assertArrayNotHasKey('immich-city', $media[1]->getExif());
        self::assertTrue($media[2]->isVideo());

        /** @var InMemoryTransport $queue */
        $queue = self::getContainer()->get('messenger.transport.media');
        self::assertCount(1, array_filter($queue->getSent(), static fn (Envelope $e): bool => $e->getMessage() instanceof ProcessVideo), 'La vidéo part en conversion.');

        // Album « Publié sur Socialize » : créé une fois, puis réutilisé.
        $this->publish(['i-'.FakeImmich::PHOTO_HONFLEUR], []);
        $created = FakeImmich::requestsTo('POST', '#^/albums$#');
        self::assertCount(1, $created);
        self::assertSame('Publié sur Socialize', $created[0]['body']['albumName'] ?? null);
        $album = array_find(FakeImmich::$albums, static fn (array $a): bool => 'Publié sur Socialize' === $a['name']);
        self::assertSame([FakeImmich::PHOTO_ETRETAT, FakeImmich::VIDEO_LAC, FakeImmich::PHOTO_HONFLEUR], $album['assets'] ?? null);
    }

    public function testAStoryFromImmich(): void
    {
        $this->login($this->connected('camille'));
        $crawler = $this->client->request('GET', '/stories/nouvelle');
        $values = $this->values($crawler->selectButton('Partager la story')->form()->getPhpValues());
        $values['compose_story']['order'] = 'i-'.FakeImmich::PHOTO_HONFLEUR;
        $this->client->request('POST', '/stories/nouvelle', $values);
        self::assertResponseRedirects();

        $stories = self::getContainer()->get(StoryRepository::class)->findAll();
        self::assertCount(1, $stories);
        self::assertSame('Honfleur', $stories[0]->getMedia()?->getExif()['immich-city'] ?? null);
        self::assertSame([], FakeImmich::requestsTo('POST', '#^/albums$#'), 'Sans l’option, aucun album n’est créé.');
    }

    public function testAHighlightCoverFromImmich(): void
    {
        $camille = $this->connected('camille');
        $story = self::getContainer()->get(StoryComposer::class)->publish($camille->getAccount(), [$this->jpeg(1080, 1920)], null, Visibility::Public)[0];
        $highlight = self::getContainer()->get(StoryActions::class)->pin($story, null, 'Normandie');
        $this->login($camille);

        $crawler = $this->client->request('GET', '/a-la-une/'.$highlight->getPublicId().'/modifier');
        self::assertSelectorExists('[data-source-name="immich"]', 'La photothèque est proposée pour la couverture.');
        $values = $this->values($crawler->selectButton('Enregistrer')->form()->getPhpValues());
        $values['highlight']['order'] = 'i-'.FakeImmich::VIDEO_LAC;
        $this->client->request('POST', '/a-la-une/'.$highlight->getPublicId().'/modifier', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors', 'Choisissez une photo pour la couverture');

        $values['highlight']['order'] = 'i-'.FakeImmich::PHOTO_HONFLEUR;
        $this->client->request('POST', '/a-la-une/'.$highlight->getPublicId().'/modifier', $values);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $saved = self::getContainer()->get(HighlightRepository::class)->findByPublicId($highlight->getPublicId());
        self::assertStringStartsWith('highlights/', (string) $saved?->getCoverPath());
    }

    public function testAnUnreachableImmichLeavesNoHalfCreatedPost(): void
    {
        $this->login($this->connected('camille'));
        FakeImmich::$down = true;

        $crawler = $this->client->request('GET', '/publier');
        self::assertResponseIsSuccessful('La page reste utilisable avec les fichiers de l’appareil.');
        $values = $this->values($crawler->selectButton('Publier')->form()->getPhpValues());
        $values['compose_post']['order'] = 'i-'.FakeImmich::PHOTO_ETRETAT;
        $this->client->request('POST', '/publier', $values);
        self::assertResponseStatusCodeSame(422, 'Le formulaire revient avec l’erreur.');
        self::assertSelectorTextContains('.field-errors', 'Immich est injoignable');
        self::assertCount(0, self::getContainer()->get(PostRepository::class)->findAll());

        $values['compose_post']['order'] = 'i-'.FakeImmich::PHOTO_ETRETAT.',x-1';
        FakeImmich::$down = false;
        $this->client->request('POST', '/publier', $values);
        self::assertSelectorTextContains('.field-errors', 'sélection de médias est invalide');
    }

    /**
     * @param list<string>          $order
     * @param array<string, string> $alts
     */
    private function publish(array $order, array $alts): Post
    {
        $crawler = $this->client->request('GET', '/publier');
        $values = $this->values($crawler->selectButton('Publier')->form()->getPhpValues());
        $values['compose_post']['order'] = implode(',', $order);
        $values['compose_post']['altTexts'] = $alts;
        $values['compose_post']['caption'] = 'Depuis Immich';
        $files = \in_array('n-0', $order, true) ? ['compose_post' => ['media' => [$this->jpeg(400, 500)]]] : [];
        $this->client->request('POST', '/publier', $values, $files);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        // Redirection vers la publication, ou vers le profil si elle attend la conversion d'une vidéo : la plus récente.
        $posts = self::getContainer()->get(PostRepository::class)->findBy([], ['publishedAt' => 'DESC', 'id' => 'DESC'], 1);

        return $posts[0] ?? throw new \LogicException('Publication introuvable.');
    }

    /**
     * Valeurs d'un formulaire, pour y ajouter les champs que le JavaScript remplit (ordre, textes alternatifs).
     *
     * @param array<mixed> $values
     *
     * @return array<string, array<string, mixed>>
     */
    private function values(array $values): array
    {
        /** @var array<string, array<string, mixed>> $typed */
        $typed = $values;

        return $typed;
    }

    private function connected(string $username, bool $addToAlbum = false): User
    {
        $user = $this->register($username);
        self::getContainer()->get(ImmichConnections::class)->connect($user->getAccount(), FakeImmich::SERVER, FakeImmich::KEY, $addToAlbum);

        return $user;
    }

    private function connection(string $username): ?ImmichConnection
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $connections = self::getContainer()->get(ImmichConnectionRepository::class);

        return array_find($connections->findAll(), static fn (ImmichConnection $c): bool => $c->getAccount()->getUsername() === $username);
    }
}
