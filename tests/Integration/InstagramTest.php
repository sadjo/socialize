<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Account\User;
use App\Core\Domain\Post\CrosspostTarget;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Crosspost\Meta\Application\InstagramConnections;
use App\Crosspost\Meta\Application\RefreshInstagramTokens;
use App\Crosspost\Meta\Application\RefreshInstagramTokensHandler;
use App\Crosspost\Meta\Domain\CrosspostStatus;
use App\Crosspost\Meta\Domain\InstagramAppSettings;
use App\Crosspost\Meta\Domain\InstagramConnection;
use App\Crosspost\Meta\Domain\InstagramCrosspost;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramAppSettingsRepository;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramConnectionRepository;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramCrosspostRepository;
use App\Crosspost\Meta\Infrastructure\InstagramVault;
use App\Media\Application\PostComposer;
use App\Media\Application\ProcessVideo;
use App\Media\Application\ProcessVideoHandler;
use App\Media\Application\StoryComposer;
use App\Tests\AppWebTestCase;
use Symfony\Component\HttpFoundation\File\File;

final class InstagramTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeInstagram::reset();
    }

    public function testConnectingThroughInstagramStoresAnEncryptedToken(): void
    {
        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/reglages/instagram');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.ptabs a[href="/reglages/instagram"].on');
        self::assertSelectorTextContains('main code', 'http://localhost/reglages/instagram/retour');

        // Vers Instagram : application de l'instance, adresse de retour, droits de publication, état aléatoire.
        $this->client->submit($crawler->selectButton('Se connecter à Instagram')->form());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('1234567890', $query['client_id'] ?? null);
        self::assertSame('http://localhost/reglages/instagram/retour', $query['redirect_uri'] ?? null);
        self::assertSame('instagram_business_basic,instagram_business_content_publish', $query['scope'] ?? null);
        $state = $query['state'] ?? null;
        self::assertIsString($state);

        // Retour avec un autre état : refusé.
        $this->client->request('GET', '/reglages/instagram/retour', ['code' => FakeInstagram::CODE, 'state' => 'autre']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'recommencez');
        self::assertNull($this->connection());

        // Bon état (l'état n'est valable qu'une fois : on repasse par Instagram).
        $crawler = $this->client->request('GET', '/reglages/instagram');
        $this->client->submit($crawler->selectButton('Se connecter à Instagram')->form());
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->client->request('GET', '/reglages/instagram/retour', ['code' => FakeInstagram::CODE.'#_', 'state' => $query['state'] ?? '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Instagram connecté (@camille.photo).');
        self::assertSelectorTextContains('.zip', 'Connecté');

        $connection = $this->connection();
        self::assertInstanceOf(InstagramConnection::class, $connection);
        self::assertSame(FakeInstagram::USER_ID, $connection->getInstagramUserId());
        self::assertStringNotContainsString('jeton-long-1', $connection->getTokenEncrypted());
        self::assertSame('jeton-long-1', self::getContainer()->get(InstagramVault::class)->decrypt($connection->getTokenEncrypted()));
        self::assertGreaterThan(new \DateTimeImmutable('+59 days'), $connection->getTokenExpiresAt());

        // Règles : abonnés aussi, et stories.
        $crawler = $this->client->request('GET', '/reglages/instagram');
        $form = $crawler->selectButton('Enregistrer les règles')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['instagram_rules'] ?? null);
        $values['instagram_rules']['visibilities'] = ['public', 'followers'];
        $values['instagram_rules']['stories'] = '1';
        $this->client->request('POST', '/reglages/instagram', $values);
        self::assertResponseRedirects('/reglages/instagram');
        $connection = $this->connection();
        self::assertInstanceOf(InstagramConnection::class, $connection);
        self::assertTrue($connection->checksByDefault(Visibility::Followers));
        self::assertTrue($connection->checksByDefault(Visibility::Public, story: true));
        self::assertFalse($connection->checksByDefault(Visibility::Private));

        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->selectButton('Déconnecter')->form());
        self::assertNull($this->connection());
    }

    public function testEachAccountCanUseItsOwnMetaApp(): void
    {
        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/reglages/instagram');
        self::assertSelectorTextContains('#application-meta', 'celle de l’instance est utilisée');

        // Sans clé secrète, une première application est refusée.
        $form = $crawler->selectButton('Enregistrer l’application')->form();
        $form['instagram_app[appId]'] = '555000111';
        $this->client->submit($form);
        self::assertSelectorTextContains('#application-meta', 'Indiquez la clé secrète');
        self::assertNull($this->ownApp());

        $form['instagram_app[secret]'] = 'secret-a-moi';
        $this->client->submit($form);
        self::assertResponseRedirects('/reglages/instagram');
        $own = $this->ownApp();
        self::assertInstanceOf(InstagramAppSettings::class, $own);
        self::assertSame('555000111', $own->getAppId());
        self::assertStringNotContainsString('secret-a-moi', $own->getSecretEncrypted(), 'Clé secrète chiffrée.');
        $crawler = $this->client->followRedirect();
        self::assertSame('', $crawler->filter('input[name="instagram_app[secret]"]')->attr('value') ?? '', 'Jamais réaffichée.');
        self::assertSelectorTextContains('#application-meta', '555000111');

        // Connexion avec son application : son identifiant vers Instagram, sa clé secrète à l'échange du code.
        $this->client->submit($crawler->selectButton('Se connecter à Instagram')->form());
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        self::assertSame('555000111', $query['client_id'] ?? null);
        FakeInstagram::$secret = 'secret-a-moi';
        $this->client->request('GET', '/reglages/instagram/retour', ['code' => FakeInstagram::CODE, 'state' => $query['state'] ?? '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Instagram connecté');
        $exchange = array_values(array_filter(FakeInstagram::$requests, static fn (array $r): bool => '/oauth/access_token' === $r['path']));
        self::assertSame('secret-a-moi', $exchange[0]['params']['client_secret'] ?? null);

        // Nouvel identifiant, clé laissée vide : la clé enregistrée est gardée.
        $form = $this->client->getCrawler()->selectButton('Enregistrer l’application')->form();
        $form['instagram_app[appId]'] = '555000222';
        $this->client->submit($form);
        $own = $this->ownApp();
        self::assertInstanceOf(InstagramAppSettings::class, $own);
        self::assertSame('555000222', $own->getAppId());
        self::assertSame('secret-a-moi', self::getContainer()->get(InstagramVault::class)->decrypt($own->getSecretEncrypted()));

        // Application retirée : la connexion reste, une nouvelle connexion passe par l'application de l'instance.
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Retirer mon application')->form());
        self::assertNull($this->ownApp());
        self::assertInstanceOf(InstagramConnection::class, $this->connection());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Reconnecter Instagram')->form());
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        self::assertSame('1234567890', $query['client_id'] ?? null);
    }

    public function testThePublishBoxFollowsTheRulesAndOnlyAppearsWhenConnected(): void
    {
        $this->login($this->register('hugo'));
        $this->client->request('GET', '/publier');
        self::assertSelectorNotExists('[data-controller="crosspost"]', 'Sans Instagram connecté, pas de case.');

        $this->login($this->connected('camille'));
        $this->client->request('GET', '/publier');
        self::assertSelectorExists('[data-controller="crosspost"][data-crosspost-field-value="compose_post[visibility]"]');
        self::assertSame('["public"]', $this->client->getCrawler()->filter('[data-controller="crosspost"]')->attr('data-crosspost-defaults-value'));
        self::assertSelectorExists('input[name="compose_post[crosspostInstagram]"][checked]', 'Publique par défaut : cochée.');
        self::assertSelectorTextContains('[data-controller="crosspost"]', 'Publier aussi sur Instagram (@camille.photo)');

        // Stories : règles non appliquées par défaut.
        $this->client->request('GET', '/stories/nouvelle');
        self::assertSelectorExists('input[name="compose_story[crosspostInstagram]"]:not([checked])');
        self::assertSame('[]', $this->client->getCrawler()->filter('[data-controller="crosspost"]')->attr('data-crosspost-defaults-value'));
    }

    public function testAPostAlreadyOnlineCanBeSentAfterwards(): void
    {
        $camille = $this->register('camille');
        // Publiée avant la connexion d'Instagram, sans crosspost.
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Souvenir de l’été', [['file' => $this->jpeg(), 'alt' => null]], Visibility::Followers);
        $this->login($camille);
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorNotExists('form[action$="/instagram"]', 'Instagram non connecté : pas de bouton.');

        self::getContainer()->get(InstagramConnections::class)->connect($this->reloadUser($camille)->getAccount(), FakeInstagram::CODE, 'http://localhost/reglages/instagram/retour');
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $form = $crawler->filter('form[action="/p/'.$post->getPublicId().'/instagram"]');
        self::assertCount(1, $form);
        self::assertStringContainsString('visible de tous', (string) $form->attr('data-turbo-confirm'), 'Publication réservée aux abonnés : avertissement.');
        $this->client->submit($crawler->selectButton('Publier sur Instagram')->form());
        self::assertResponseRedirects('/p/'.$post->getPublicId());

        self::assertCount(1, FakeInstagram::publishedContainers());
        self::assertSame('Souvenir de l’été', FakeInstagram::publishedContainers()[0]['params']['caption'] ?? null);
        $crosspost = $this->crosspostOf($post);
        self::assertSame(CrosspostStatus::Published, $crosspost->getStatus());
        self::assertTrue($this->reload($post)->wantsCrosspost(CrosspostTarget::Instagram));

        // Déjà envoyée : plus de bouton, le lien vers Instagram ; un second envoi ne republie pas.
        $this->client->followRedirect();
        self::assertSelectorNotExists('form[action$="/instagram"]');
        self::assertSelectorTextContains('.crosspost-status', 'Publiée sur Instagram');
        $this->client->request('POST', '/p/'.$post->getPublicId().'/instagram', ['_token' => 'invalide']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, FakeInstagram::$published, 'Rien de republié.');

        // Une publication d'un autre compte : introuvable.
        $other = self::getContainer()->get(PostComposer::class)->publish($this->register('hugo')->getAccount(), 'Mer', [['file' => $this->jpeg(), 'alt' => null]]);
        $this->client->request('POST', '/p/'.$other->getPublicId().'/instagram', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPhotoGoesToInstagramAsJpegWithinTheAcceptedProportions(): void
    {
        $this->login($this->connected('camille'));
        $crawler = $this->client->request('GET', '/publier');
        $form = $crawler->selectButton('Publier')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['compose_post'] ?? null);
        $values['compose_post']['caption'] = 'Coucher de soleil avec @alice@remote.test #mer';
        $values['compose_post']['altTexts'] = ['Soleil sur la mer'];
        $values['compose_post']['crosspostInstagram'] = '1';
        // Photo très verticale (2:5) : complétée de bandes jusqu'à 4:5, sans recadrage.
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg(400, 1000)]]]);
        self::assertResponseRedirects();
        $post = $this->lastPost();

        $published = FakeInstagram::publishedContainers();
        self::assertCount(1, $published);
        $params = $published[0]['params'];
        self::assertStringEndsWith('.jpg', $params['image_url'] ?? '');
        self::assertStringStartsWith('http://localhost/m/crosspost/', $params['image_url'] ?? '');
        self::assertSame('Coucher de soleil avec alice@remote.test #mer', $params['caption'] ?? null, 'Adresse du fédivers en texte simple.');
        self::assertSame('Soleil sur la mer', $params['alt_text'] ?? null);
        $image = $published[0]['image'];
        self::assertNotNull($image);
        self::assertEqualsWithDelta(0.8, $image['width'] / $image['height'], 0.01);

        $crosspost = $this->crosspostOf($post);
        self::assertSame(CrosspostStatus::Published, $crosspost->getStatus());
        self::assertSame('https://www.instagram.com/p/media-1/', $crosspost->getPermalink());
        self::assertDirectoryDoesNotExist(\dirname(__DIR__, 2).'/var/test-storage/public/crosspost/'.$crosspost->getId()->toBase58(), 'Copies temporaires supprimées.');

        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorTextContains('.crosspost-status', 'Publiée sur Instagram');
        self::assertSelectorExists('.crosspost-status a[href="https://www.instagram.com/p/media-1/"]');

        // Sans la case : rien n'est envoyé.
        $values['compose_post']['crosspostInstagram'] = '0';
        unset($values['compose_post']['crosspostInstagram']);
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg()]]]);
        self::assertCount(1, FakeInstagram::publishedContainers());
    }

    public function testACarouselWaitsForItsVideoThenIsPublished(): void
    {
        $camille = $this->connected('camille');
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Week-end', [
            ['file' => $this->jpeg(1200, 900), 'alt' => 'Plage'],
            ['file' => $this->jpeg(600, 900), 'alt' => null],
            ['file' => new File($this->mp4()->getPathname()), 'alt' => null],
        ], crosspost: [CrosspostTarget::Instagram]);
        self::assertSame([], FakeInstagram::$containers, 'Rien ne part avant que la vidéo soit prête.');

        $video = array_first(array_filter($post->getMedia()->toArray(), static fn ($m): bool => $m->isVideo()));
        self::assertInstanceOf(\App\Core\Domain\Media\MediaAttachment::class, $video);
        FakeInstagram::$videoChecks = 2;
        self::getContainer()->get(ProcessVideoHandler::class)(new ProcessVideo($video->getId()->toRfc4122()));

        $published = FakeInstagram::publishedContainers();
        self::assertCount(1, $published);
        self::assertSame('CAROUSEL', $published[0]['params']['media_type'] ?? null);
        $children = explode(',', $published[0]['params']['children'] ?? '');
        self::assertCount(3, $children);
        $items = array_map(static fn (string $id): array => FakeInstagram::$containers[$id], $children);
        self::assertSame('true', $items[0]['params']['is_carousel_item'] ?? null);
        self::assertSame('Plage', $items[0]['params']['alt_text'] ?? null);
        self::assertSame('VIDEO', $items[2]['params']['media_type'] ?? null);
        self::assertStringEndsWith('.mp4', $items[2]['params']['video_url'] ?? '');
        // Toutes les photos sur les proportions de la première (4:3), que le carrousel impose.
        foreach ([0, 1] as $i) {
            $image = $items[$i]['image'];
            self::assertNotNull($image);
            self::assertEqualsWithDelta(4 / 3, $image['width'] / $image['height'], 0.01);
        }
        self::assertSame(CrosspostStatus::Published, $this->crosspostOf($this->reload($post))->getStatus());
    }

    public function testStoriesAndReelsFollowInstagramRules(): void
    {
        $camille = $this->connected('camille');
        [$story] = self::getContainer()->get(StoryComposer::class)->publish($camille->getAccount(), [new File($this->jpeg(1000, 1000)->getPathname())], crosspost: [CrosspostTarget::Instagram]);
        $published = FakeInstagram::publishedContainers();
        self::assertCount(1, $published);
        self::assertSame('STORIES', $published[0]['params']['media_type'] ?? null);
        $image = $published[0]['image'];
        self::assertNotNull($image);
        self::assertEqualsWithDelta(9 / 16, $image['width'] / $image['height'], 0.01, 'Story en 9:16, avec des bandes.');
        self::assertArrayNotHasKey('caption', $published[0]['params']);
        self::assertSame(CrosspostStatus::Published, self::getContainer()->get(InstagramCrosspostRepository::class)->forStory($story)?->getStatus());

        // Une vidéo seule devient un Reel, qui doit durer au moins 3 secondes : refusée avant l'envoi.
        $post = self::getContainer()->get(PostComposer::class)->publish($this->reloadUser($camille)->getAccount(), 'Trop court', [['file' => new File($this->mp4()->getPathname()), 'alt' => null]], crosspost: [CrosspostTarget::Instagram]);
        $video = $post->getMedia()->first();
        self::assertNotFalse($video);
        self::getContainer()->get(ProcessVideoHandler::class)(new ProcessVideo($video->getId()->toRfc4122()));
        $crosspost = $this->crosspostOf($this->reload($post));
        self::assertSame(CrosspostStatus::Failed, $crosspost->getStatus());
        self::assertStringContainsString('Un Reel Instagram dure de 3 secondes à 15 minutes. Celle-ci dure 2 s.', (string) $crosspost->getError());
        self::assertCount(1, FakeInstagram::publishedContainers());
    }

    public function testAFailureIsExplainedAndCanBeRetried(): void
    {
        $camille = $this->login($this->connected('camille'));
        FakeInstagram::$nextContainerFails = true;
        $post = $this->publish($camille, 'Raté une fois');
        $crosspost = $this->crosspostOf($post);
        self::assertSame(CrosspostStatus::Failed, $crosspost->getStatus());
        self::assertStringContainsString('Instagram a refusé un média', (string) $crosspost->getError());

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorTextContains('.crosspost-status', 'Échec de l’envoi vers Instagram');
        $this->client->submit($crawler->selectButton('Réessayer')->form());
        self::assertResponseRedirects('/p/'.$post->getPublicId());
        self::assertSame(CrosspostStatus::Published, $this->crosspostOf($this->reload($post))->getStatus());

        // Un autre compte ne peut pas relancer l'envoi.
        $this->login($this->register('hugo'));
        $this->client->request('POST', '/instagram/envois/'.$crosspost->getId()->toRfc4122().'/reessayer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testARefusedTokenAsksToReconnect(): void
    {
        $camille = $this->login($this->connected('camille'));
        FakeInstagram::$token = 'jeton-revoque';
        $crosspost = $this->crosspostOf($this->publish($camille, 'Jeton révoqué'));
        self::assertSame(CrosspostStatus::Failed, $crosspost->getStatus());
        self::assertStringContainsString('reconnectez Instagram', (string) $crosspost->getError());

        $this->client->request('GET', '/reglages/instagram');
        self::assertSelectorTextContains('.zip', 'À reconnecter');
        $this->client->request('GET', '/publier');
        self::assertSelectorNotExists('[data-controller="crosspost"]', 'Plus de case tant qu’Instagram n’est pas reconnecté.');
    }

    public function testTokensAreRenewedOnceAWeek(): void
    {
        $this->connected('camille');
        $handler = self::getContainer()->get(RefreshInstagramTokensHandler::class);
        $handler(new RefreshInstagramTokens());
        self::assertSame('jeton-long-1', $this->token(), 'Jeton récent : gardé.');

        self::getContainer()->get(\Doctrine\DBAL\Connection::class)->executeStatement('UPDATE instagram_connections SET refreshed_at = ?', [new \DateTimeImmutable('-8 days')->format('Y-m-d H:i:s')]);
        self::getContainer()->get('doctrine')->getManager()->clear();
        $handler(new RefreshInstagramTokens());
        self::assertSame('jeton-long-2', $this->token());
        self::assertSame('jeton-long-2', FakeInstagram::$token);
    }

    public function testInstagramIsReservedToAccountsAllowedToPublish(): void
    {
        $this->register('alice');
        $this->login($this->register('hugo', publisher: false));
        $this->client->request('GET', '/reglages/instagram');
        self::assertResponseStatusCodeSame(403);
    }

    private function connected(string $username): User
    {
        $user = $this->register($username);
        self::getContainer()->get(InstagramConnections::class)->connect($user->getAccount(), FakeInstagram::CODE, 'http://localhost/reglages/instagram/retour');

        return $user;
    }

    private function publish(User $author, string $caption): Post
    {
        return self::getContainer()->get(PostComposer::class)->publish($this->reloadUser($author)->getAccount(), $caption, [['file' => $this->jpeg(), 'alt' => null]], Visibility::Public, crosspost: [CrosspostTarget::Instagram]);
    }

    private function ownApp(): ?InstagramAppSettings
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $all = self::getContainer()->get(InstagramAppSettingsRepository::class)->findAll();

        return $all[0] ?? null;
    }

    private function connection(): ?InstagramConnection
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $all = self::getContainer()->get(InstagramConnectionRepository::class)->findAll();

        return $all[0] ?? null;
    }

    private function token(): string
    {
        $connection = $this->connection();
        self::assertInstanceOf(InstagramConnection::class, $connection);

        return self::getContainer()->get(InstagramVault::class)->decrypt($connection->getTokenEncrypted());
    }

    private function crosspostOf(Post $post): InstagramCrosspost
    {
        $crosspost = self::getContainer()->get(InstagramCrosspostRepository::class)->forPost($post);
        self::assertInstanceOf(InstagramCrosspost::class, $crosspost);

        return $crosspost;
    }

    private function lastPost(): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $posts = self::getContainer()->get(PostRepository::class)->findBy([], ['publishedAt' => 'DESC'], 1);
        self::assertCount(1, $posts);

        return $posts[0];
    }

    private function reload(Post $post): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(PostRepository::class)->find($post->getId());
        self::assertInstanceOf(Post::class, $fresh);

        return $fresh;
    }

    private function reloadUser(User $user): User
    {
        $fresh = self::getContainer()->get(\App\Core\Infrastructure\Doctrine\UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }
}
