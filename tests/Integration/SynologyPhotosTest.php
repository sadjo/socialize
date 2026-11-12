<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Account\User;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Integration\SynologyPhotos\Application\SynologyPhotosConnections;
use App\Integration\SynologyPhotos\Domain\SynologyPhotosConnection;
use App\Integration\SynologyPhotos\Infrastructure\Doctrine\SynologyPhotosConnectionRepository;
use App\Integration\SynologyPhotos\Infrastructure\SynologyPhotosVault;
use App\Tests\AppWebTestCase;

final class SynologyPhotosTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeSynologyPhotos::reset();
        FakeImmich::reset();
    }

    public function testConnectingOpensASessionAndNeverStoresThePassword(): void
    {
        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/reglages/synology-photos');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.ptabs a[href="/reglages/synology-photos"].on');

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'synology_photos_settings[serverUrl]' => FakeSynologyPhotos::SERVER,
            'synology_photos_settings[userName]' => FakeSynologyPhotos::USER,
            'synology_photos_settings[password]' => 'mauvais',
        ]));
        self::assertSelectorTextContains('.form-errors', 'Identifiant ou mot de passe refusé');
        self::assertNull($this->connection('camille'));

        $crawler = $this->client->request('GET', '/reglages/synology-photos');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'synology_photos_settings[serverUrl]' => FakeSynologyPhotos::SERVER.'/webapi/entry.cgi',
            'synology_photos_settings[userName]' => FakeSynologyPhotos::USER,
            'synology_photos_settings[password]' => FakeSynologyPhotos::PASSWORD,
        ]));
        self::assertResponseRedirects('/reglages/synology-photos');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Synology Photos connecté (camille) : 3 éléments dans l’espace personnel, 2 dans l’espace partagé, 1 album.');
        self::assertSelectorTextContains('.zip', 'Connecté');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(FakeSynologyPhotos::PASSWORD, $html, 'Le mot de passe n’est jamais réaffiché.');

        $connection = $this->connection('camille');
        self::assertInstanceOf(SynologyPhotosConnection::class, $connection);
        self::assertSame(FakeSynologyPhotos::SERVER, $connection->getServerUrl(), 'Adresse sans chemin d’API.');
        self::assertSame(FakeSynologyPhotos::USER, $connection->getUserName());
        $session = self::getContainer()->get(SynologyPhotosVault::class)->decrypt($connection->getSessionEncrypted());
        self::assertSame(FakeSynologyPhotos::$sessions, [$session], 'Seule la session est gardée…');
        self::assertStringNotContainsString($session, $connection->getSessionEncrypted(), '… chiffrée.');
        self::assertStringNotContainsString($session, $html);
        $row = self::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchAssociative('SELECT * FROM synology_photos_connections');
        self::assertIsArray($row);
        self::assertStringNotContainsString(FakeSynologyPhotos::PASSWORD, implode('|', array_filter($row, \is_string(...))), 'Le mot de passe n’est pas enregistré.');

        // Déconnexion : la session est fermée sur le NAS.
        $this->client->submit($this->client->getCrawler()->selectButton('Déconnecter Synology Photos')->form());
        self::assertNull($this->connection('camille'));
        self::assertSame([], FakeSynologyPhotos::$sessions);
    }

    public function testTheOneTimeCodeIsAskedWhenTheNasRequiresIt(): void
    {
        FakeSynologyPhotos::$otpRequired = true;
        $this->login($this->register('camille'));
        $values = [
            'synology_photos_settings[serverUrl]' => FakeSynologyPhotos::SERVER,
            'synology_photos_settings[userName]' => FakeSynologyPhotos::USER,
            'synology_photos_settings[password]' => FakeSynologyPhotos::PASSWORD,
        ];
        $crawler = $this->client->request('GET', '/reglages/synology-photos');
        $this->client->submit($crawler->selectButton('Se connecter')->form($values));
        self::assertSelectorTextContains('.form-errors', 'double authentification');

        $crawler = $this->client->request('GET', '/reglages/synology-photos');
        $this->client->submit($crawler->selectButton('Se connecter')->form($values + ['synology_photos_settings[otp]' => '000000']));
        self::assertSelectorTextContains('.form-errors', 'Code à usage unique refusé');

        $crawler = $this->client->request('GET', '/reglages/synology-photos');
        $this->client->submit($crawler->selectButton('Se connecter')->form($values + ['synology_photos_settings[otp]' => FakeSynologyPhotos::OTP]));
        self::assertResponseRedirects('/reglages/synology-photos');
        self::assertInstanceOf(SynologyPhotosConnection::class, $this->connection('camille'));
    }

    public function testTheLibraryAndThumbnailsAreReservedToTheConnectedAccount(): void
    {
        $this->connected('camille');
        $this->login($this->register('hugo'));
        $this->client->request('GET', '/synology-photos/phototheque');
        self::assertResponseStatusCodeSame(404, 'Sans connexion Synology Photos, pas de photothèque.');
        $this->client->request('GET', '/publier');
        self::assertSelectorNotExists('.src-tabs');

        $this->login($this->user('camille'));
        $this->client->request('GET', '/publier');
        self::assertSelectorExists('.src-tabs [data-compose-source-param="synology"]');
        self::assertSelectorNotExists('.src-tabs [data-compose-source-param="immich"]');
        self::assertSelectorTextContains('#synology-album', 'Vacances (2)');

        // Espace personnel, du plus récent au plus ancien, regroupé par jour (heures du NAS affichées telles quelles).
        $crawler = $this->client->request('GET', '/synology-photos/phototheque');
        self::assertResponseIsSuccessful();
        self::assertSame(['s-p-501', 's-p-502', 's-p-503'], $crawler->filter('.imm-tile .imm-pick')->each(static fn ($tile): ?string => $tile->attr('data-compose-token-param')));
        self::assertSelectorTextContains('.imm-day', 'Lundi 9 novembre 2026 · Saint-Malo, France');
        self::assertStringContainsString('17:30', (string) $crawler->filter('.imm-tile .imm-pick')->first()->attr('aria-label'));
        self::assertStringStartsWith('Voir en grand : Photo du', (string) $crawler->filter('.imm-tile .imm-zoom[data-action="library-preview#open"]')->first()->attr('aria-label'));
        self::assertSame('/synology-photos/apercu/p/9501_1700000000', $crawler->filter('.imm-tile img')->first()->attr('src'));

        // Espace partagé.
        $crawler = $this->client->request('GET', '/synology-photos/phototheque?espace=t');
        self::assertSame(['s-t-601', 's-t-602'], $crawler->filter('.imm-tile .imm-pick')->each(static fn ($tile): ?string => $tile->attr('data-compose-token-param')));

        // Aperçu : image en grand ; vidéo seulement si le NAS en a une version lisible.
        $tile = $crawler->filter('.imm-tile')->eq(1);
        self::assertSame('/synology-photos/grand/t/9602_1700000000', $tile->attr('data-preview-image'));
        self::assertSame('video', $tile->attr('data-preview-kind'));
        self::assertNull($tile->attr('data-preview-video'), 'Seulement « flv » : pas de lecture.');
        $crawler = $this->client->request('GET', '/synology-photos/phototheque');
        $video = $crawler->filter('.imm-tile')->eq(1);
        self::assertSame('/synology-photos/video/p/502/high', $video->attr('data-preview-video'), 'La plus légère des versions lisibles.');
        self::assertNull($crawler->filter('.imm-tile')->first()->attr('data-preview-video'));

        // Un album mêle les deux espaces : chaque média garde le sien.
        $crawler = $this->client->request('GET', '/synology-photos/phototheque?album='.FakeSynologyPhotos::ALBUM_VACANCES);
        self::assertSame(['s-p-501', 's-t-601'], $crawler->filter('.imm-tile .imm-pick')->each(static fn ($tile): ?string => $tile->attr('data-compose-token-param')));

        // Plus d'une page : la suite se charge au défilement (contrôleur library-more), ou par le lien du cadre.
        $crawler = $this->client->request('GET', '/synology-photos/phototheque?album='.FakeSynologyPhotos::ALBUM_LONG);
        self::assertCount(60, $crawler->filter('.imm-tile'));
        $next = $crawler->filter('turbo-frame#synology-page-2[data-controller="library-more"]');
        self::assertCount(1, $next);
        self::assertSame($next->attr('src'), $next->filter('a')->attr('href'), 'Lien « Voir la suite » de secours.');
        $crawler = $this->client->request('GET', (string) $next->attr('src'));
        self::assertCount(1, $crawler->filter('turbo-frame#synology-page-2 .imm-tile'));
        self::assertSelectorNotExists('turbo-frame#synology-page-3');

        // Aller à une date : ce jour-là ou avant.
        $crawler = $this->client->request('GET', '/synology-photos/phototheque?date=2026-11-08');
        self::assertSame(['s-p-502', 's-p-503'], $crawler->filter('.imm-tile .imm-pick')->each(static fn ($tile): ?string => $tile->attr('data-compose-token-param')));

        // Vignette relayée ; l'identifiant de session part dans le corps de la requête, jamais dans l'adresse.
        $this->client->request('GET', '/synology-photos/apercu/t/9601_1700000000');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $this->client->request('GET', '/synology-photos/grand/t/9601_1700000000');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        self::assertSame('xl', array_last(FakeSynologyPhotos::requestsTo('SYNO.FotoTeam.Thumbnail', 'get'))['size'] ?? null);

        // Vidéo relayée par plages, par l'API de lecture de son espace ; version inconnue ou absente : rien.
        $this->client->request('GET', '/synology-photos/video/p/502/high', server: ['HTTP_RANGE' => 'bytes=0-99']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Type', 'video/mp4');
        self::assertResponseHeaderSame('Content-Length', '100');
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes', 'Ajouté : le NAS ne l’envoie pas avec une réponse partielle.');
        $stream = array_last(FakeSynologyPhotos::requestsTo('SYNO.Foto.Streaming', 'streaming'));
        self::assertSame(['item', 'high'], [$stream['type'] ?? null, $stream['quality'] ?? null]);
        foreach (['/synology-photos/video/p/502/medium', '/synology-photos/video/t/602/flv', '/synology-photos/video/p/502/inconnue'] as $wrong) {
            $this->client->request('GET', $wrong);
            self::assertResponseStatusCodeSame(404, $wrong);
        }

        // Mauvais espace, ou identifiant de l'élément au lieu de celui de l'unité : le NAS ne trouve rien.
        foreach (['/synology-photos/apercu/p/9601_1700000000', '/synology-photos/apercu/t/601_1700000000'] as $wrong) {
            $this->client->request('GET', $wrong);
            self::assertResponseStatusCodeSame(404, $wrong);
        }
    }

    public function testAPostMixesSynologyDeviceAndImmichMedia(): void
    {
        $camille = $this->connected('camille');
        self::getContainer()->get(\App\Integration\Immich\Application\ImmichConnections::class)->connect($camille->getAccount(), FakeImmich::SERVER, FakeImmich::KEY, false);
        $this->login($camille);

        $this->client->request('GET', '/publier');
        self::assertCount(3, $this->client->getCrawler()->filter('.src-tabs button'), 'Appareil, Immich et Synology Photos.');

        $post = $this->publish(['s-p-501', 'n-0', 's-t-601', 'i-'.FakeImmich::PHOTO_ETRETAT, 's-p-502'], ['s-p-501' => 'La plage au coucher du soleil']);
        $media = array_values($post->getMedia()->toArray());
        self::assertCount(5, $media);
        self::assertSame('La plage au coucher du soleil', $media[0]->getAltText());
        self::assertSame('iPhone 16 Pro', $media[0]->getExif()['synology-camera'] ?? null, 'Les métadonnées connues du NAS sont gardées.');
        self::assertSame('48.6493', $media[0]->getExif()['gps-latitude'] ?? null);
        self::assertArrayNotHasKey('synology-camera', $media[1]->getExif());
        self::assertSame('XF16-55mm F2.8 R LM WR', $media[3]->getExif()['immich-lensModel'] ?? null);
        self::assertTrue($media[4]->isVideo());
        self::assertCount(1, FakeSynologyPhotos::requestsTo('SYNO.FotoTeam.Download', 'download'), 'L’espace partagé se télécharge par sa propre API.');
        self::assertCount(2, FakeSynologyPhotos::requestsTo('SYNO.Foto.Download', 'download'));
        self::assertSame([], glob(sys_get_temp_dir().'/socialize-synology-*') ?: [], 'Les originaux téléchargés sont supprimés.');
    }

    public function testAStoryFromSynologyPhotos(): void
    {
        $this->login($this->connected('camille'));
        $crawler = $this->client->request('GET', '/stories/nouvelle');
        self::assertSelectorExists('.src-tabs [data-compose-source-param="synology"]');
        $values = $this->values($crawler->selectButton('Partager la story')->form()->getPhpValues());
        $values['compose_story']['order'] = 's-t-601';
        $this->client->request('POST', '/stories/nouvelle', $values);
        self::assertResponseRedirects();
        self::assertCount(1, self::getContainer()->get(StoryRepository::class)->findAll());
    }

    public function testAnExpiredSessionAsksToReconnect(): void
    {
        $this->login($this->connected('camille'));
        FakeSynologyPhotos::expireSessions();

        // La page de création reste utilisable ; la photothèque propose de se reconnecter.
        $crawler = $this->client->request('GET', '/publier');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.src-tabs [data-compose-source-param="synology"]');
        self::assertTrue($this->connection('camille')?->isExpired());

        $this->client->request('GET', '/synology-photos/phototheque');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.field-errors', 'La session Synology Photos a expiré');
        self::assertSelectorExists('a[href="/reglages/synology-photos"][data-turbo-frame="_top"]');
        $before = \count(FakeSynologyPhotos::$requests);
        $this->client->request('GET', '/synology-photos/phototheque');
        self::assertCount($before, FakeSynologyPhotos::$requests, 'Session connue comme expirée : le NAS n’est plus interrogé.');

        // Publier un média du NAS : erreur expliquée, rien de publié.
        $values = $this->values($crawler->selectButton('Publier')->form()->getPhpValues());
        $values['compose_post']['order'] = 's-p-501';
        $this->client->request('POST', '/publier', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors', 'La session Synology Photos a expiré');
        self::assertCount(0, self::getContainer()->get(PostRepository::class)->findAll());

        // Réglages : état signalé, puis reconnexion avec le mot de passe (identifiant et adresse rappelés).
        $crawler = $this->client->request('GET', '/reglages/synology-photos');
        self::assertSelectorTextContains('.zip', 'Session expirée');
        $form = $crawler->selectButton('Se reconnecter')->form(['synology_photos_settings[password]' => FakeSynologyPhotos::PASSWORD]);
        self::assertSame(FakeSynologyPhotos::USER, $this->values($form->getPhpValues())['synology_photos_settings']['userName'] ?? null);
        $this->client->submit($form);
        self::assertResponseRedirects('/reglages/synology-photos');
        self::assertFalse($this->connection('camille')?->isExpired());
        $this->client->request('GET', '/synology-photos/phototheque');
        self::assertCount(3, $this->client->getCrawler()->filter('.imm-tile'));
    }

    public function testAnUnreachableNasLeavesNoHalfCreatedPost(): void
    {
        $this->login($this->connected('camille'));
        FakeSynologyPhotos::$down = true;

        $crawler = $this->client->request('GET', '/publier');
        self::assertResponseIsSuccessful('La page reste utilisable avec les fichiers de l’appareil.');
        $values = $this->values($crawler->selectButton('Publier')->form()->getPhpValues());
        $values['compose_post']['order'] = 's-p-501';
        $this->client->request('POST', '/publier', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors', 'Le NAS est injoignable');
        self::assertCount(0, self::getContainer()->get(PostRepository::class)->findAll());
        self::assertFalse($this->connection('camille')?->isExpired(), 'Une panne réseau n’est pas une session expirée.');

        FakeSynologyPhotos::$down = false;
        foreach (['s-x-501', 's-p-abc', 's-p-999'] as $token) {
            $values['compose_post']['order'] = $token;
            $this->client->request('POST', '/publier', $values);
            self::assertResponseStatusCodeSame(422, $token);
        }
        self::assertCount(0, self::getContainer()->get(PostRepository::class)->findAll());
    }

    public function testSynologyPhotosIsReservedToAccountsAllowedToPublish(): void
    {
        $this->register('alice');
        $this->login($this->register('hugo', publisher: false));
        foreach (['/reglages/synology-photos', '/synology-photos/phototheque', '/synology-photos/apercu/p/9501_1700000000', '/synology-photos/grand/p/9501_1700000000', '/synology-photos/video/p/502/high'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        $this->client->request('GET', '/reglages');
        self::assertSelectorNotExists('.ptabs a[href="/reglages/synology-photos"]');
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
        $values['compose_post']['caption'] = 'Depuis Synology Photos';
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

    private function connected(string $username): User
    {
        $user = $this->register($username);
        self::getContainer()->get(SynologyPhotosConnections::class)->connect($user->getAccount(), FakeSynologyPhotos::SERVER, FakeSynologyPhotos::USER, FakeSynologyPhotos::PASSWORD);

        return $user;
    }

    private function user(string $username): User
    {
        $user = self::getContainer()->get(\App\Core\Infrastructure\Doctrine\UserRepository::class)->loadUserByIdentifier($username);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function connection(string $username): ?SynologyPhotosConnection
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $connections = self::getContainer()->get(SynologyPhotosConnectionRepository::class);

        return array_find($connections->findAll(), static fn (SynologyPhotosConnection $c): bool => $c->getAccount()->getUsername() === $username);
    }
}
