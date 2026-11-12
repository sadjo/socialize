<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Account\User;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Integration\Immich\Application\ImmichConnections;
use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\Doctrine\ImmichConnectionRepository;
use App\Integration\Immich\Infrastructure\ImmichVault;
use App\Media\Application\ProcessVideo;
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

        $this->client->request('GET', '/immich/phototheque', server: ['HTTP_TURBO_FRAME' => 'immich-library']);
        self::assertSelectorExists('turbo-frame#immich-library:not([src])');
        self::assertSelectorCount(3, '.imm-tile');
        self::assertSelectorTextContains('.imm-day', 'Lundi 9 novembre 2026 · Honfleur, France');
        self::assertSelectorExists('.imm-tile[data-compose-token-param="i-'.FakeImmich::VIDEO_LAC.'"] .gi');
        self::assertSelectorTextContains('.imm-tile[data-compose-token-param="i-'.FakeImmich::VIDEO_LAC.'"] .gi', '0:04');

        $this->client->request('GET', '/immich/phototheque?album='.FakeImmich::ALBUM_NORMANDIE);
        self::assertSelectorCount(2, '.imm-tile');

        // Aller au 8 novembre : les médias pris ce jour-là et avant, pas ceux du 9.
        $this->client->request('GET', '/immich/phototheque?date=2026-11-08');
        self::assertSelectorCount(2, '.imm-tile');
        self::assertSelectorTextContains('.imm-day', 'Dimanche 8 novembre 2026');
        self::assertSelectorNotExists('.imm-tile[data-compose-token-param="i-'.FakeImmich::PHOTO_HONFLEUR.'"]');
        $this->client->request('GET', '/immich/phototheque?date=2026-11-08&album='.FakeImmich::ALBUM_NORMANDIE);
        self::assertSelectorCount(1, '.imm-tile');
        $this->client->request('GET', '/immich/phototheque?date=2026-01-01');
        self::assertSelectorTextContains('turbo-frame', 'jusqu’au 1 janvier 2026');
        $this->client->request('GET', '/immich/phototheque?date=pas-une-date');
        self::assertSelectorCount(3, '.imm-tile', 'Une date invalide est ignorée.');

        $this->client->request('GET', '/immich/apercu/'.FakeImmich::PHOTO_ETRETAT);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');

        $this->login($this->register('hugo'));
        $this->client->request('GET', '/immich/apercu/'.FakeImmich::PHOTO_ETRETAT);
        self::assertResponseStatusCodeSame(404, 'Sans connexion Immich, aucune vignette.');
        $this->client->request('GET', '/publier');
        self::assertSelectorNotExists('.src-tabs');
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
        $id = basename((string) $this->client->getResponse()->headers->get('Location'));

        self::getContainer()->get('doctrine')->getManager()->clear();

        return self::getContainer()->get(PostRepository::class)->findByPublicId($id) ?? throw new \LogicException('Publication introuvable.');
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
