<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\Follow;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Integration\Mastodon\Application\RefreshMastodonAccounts;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonAppRepository;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use App\Media\Application\StoryComposer;
use App\Tests\AppWebTestCase;
use App\Tests\Federation\FakeFediverse;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Connexion avec un compte Mastodon (faux serveur remote.test, permis par MASTODON_LOGIN dans .env.test).
 */
final class MastodonLoginTest extends AppWebTestCase
{
    use ConnectsWithMastodon;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
        FakeFediverse::$outbox = [];
    }

    public function testConnectingWithMastodonPilotsTheRemoteAccount(): void
    {
        // Historique du compte Mastodon, importé à la première connexion.
        FakeFediverse::$outbox = [['id' => 'https://remote.test/notes/1/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => [
            'id' => 'https://remote.test/notes/1', 'type' => 'Note', 'attributedTo' => FakeFediverse::ALICE, 'content' => '<p>Lever de soleil</p>',
            'published' => new \DateTimeImmutable('-1 day')->format(\DATE_ATOM), 'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://remote.test/media/1.jpg', 'width' => 1080, 'height' => 1350]],
        ]]];

        $crawler = $this->client->request('GET', '/connexion');
        self::assertSelectorTextContains('.mastodon-login button.pri', 'Se connecter avec remote.test');
        self::assertSelectorNotExists('.mastodon-login input[name="server"][type="text"]', 'Un seul serveur permis : un bouton, pas de champ.');
        self::assertSelectorExists('.mastodon-login form[data-turbo="false"]', 'Redirection vers un autre domaine : pas de Turbo.');
        $this->client->submit($crawler->selectButton('Se connecter avec remote.test')->form());
        $authorize = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://remote.test/oauth/authorize?', $authorize);
        parse_str((string) parse_url($authorize, \PHP_URL_QUERY), $query);
        self::assertSame('client-socialize', $query['client_id'] ?? null);
        self::assertSame('http://localhost/connexion/mastodon/retour', $query['redirect_uri'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertSame('read write:statuses write:media write:favourites write:follows', $query['scope'] ?? null);
        self::assertSame('Socialize (localhost)', FakeMastodon::requestsTo('POST', '/api/v1/apps')[0]['body']['client_name'] ?? null);

        $this->client->request('GET', '/connexion/mastodon/retour?'.http_build_query(['code' => FakeMastodon::CODE, 'state' => $query['state'] ?? '']));
        self::assertResponseRedirects('/');
        $verifier = FakeMastodon::requestsTo('POST', '/oauth/token')[0]['body']['code_verifier'] ?? '';
        self::assertIsString($verifier);
        self::assertSame($query['code_challenge'] ?? null, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'PKCE : le vérificateur correspond au défi.');

        $user = $this->mastodonUser();
        self::assertSame('mastodon:alice@remote.test', $user->getUserIdentifier());
        self::assertNotNull($user->getTokenEncrypted());
        self::assertStringNotContainsString(FakeMastodon::TOKEN, $user->getTokenEncrypted(), 'Le jeton est chiffré au repos.');
        self::assertFalse($user->getAccount()->isLocal());
        self::assertCount(1, self::getContainer()->get(PostRepository::class)->findBy(['account' => $user->getAccount()]), 'L’historique du compte est importé.');

        // Ses nouvelles publications arrivent par la mise à jour régulière (son serveur ne les envoie pas ici).
        array_unshift(FakeFediverse::$outbox, ['id' => 'https://remote.test/notes/2/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => [
            'id' => 'https://remote.test/notes/2', 'type' => 'Note', 'attributedTo' => FakeFediverse::ALICE, 'content' => '<p>Coucher de soleil</p>',
            'published' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM), 'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://remote.test/media/2.jpg', 'width' => 1080, 'height' => 1350]],
        ]]);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RefreshMastodonAccounts());
        self::assertCount(2, self::getContainer()->get(PostRepository::class)->findBy(['account' => $user->getAccount()]));

        // Connecté : son profil est celui de son compte Mastodon ; ni notifications ni réglages ici.
        $this->client->request('GET', '/');
        self::assertSelectorExists('.side a[href="/@alice@remote.test"]');
        self::assertSelectorNotExists('.side a[href="/notifications"]');
        self::assertSelectorNotExists('.side a[href="/reglages"]');
        self::assertSelectorExists('.side a[href="https://remote.test/@alice"]');
        $this->client->request('GET', '/@alice@remote.test');
        self::assertResponseIsSuccessful();

        // Ce qui se gère sur son serveur, ou n'existe pas pour lui, est fermé.
        foreach (['/reglages', '/publier', '/stories/nouvelle', '/notifications', '/import', '/admin/comptes'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }

        // Une seconde connexion réutilise l'application enregistrée sur le serveur.
        $this->client->request('GET', '/deconnexion');
        self::assertCount(1, FakeMastodon::requestsTo('POST', '/oauth/revoke'), 'Le jeton est révoqué à la déconnexion.');
        self::assertSame(FakeMastodon::TOKEN, FakeMastodon::requestsTo('POST', '/oauth/revoke')[0]['body']['token'] ?? null);
        self::assertNull($this->mastodonUser()->getTokenEncrypted());
        $this->connect();
        self::assertCount(1, FakeMastodon::requestsTo('POST', '/api/v1/apps'));
        self::assertCount(1, self::getContainer()->get(MastodonAppRepository::class)->findAll());
        self::assertCount(1, self::getContainer()->get(MastodonUserRepository::class)->findAll());
    }

    public function testTheStoriesOfFollowedAccountsCanBeSeen(): void
    {
        $camille = $this->register('camille');
        /** @var StoryComposer $composer */
        $composer = self::getContainer()->get(StoryComposer::class);
        $story = $composer->publish($camille->getAccount(), [$this->jpeg(1080, 1920)], 'Pour mes abonnés', Visibility::Followers)[0];

        $this->connect();
        $this->client->request('GET', '/@camille/stories');
        self::assertResponseStatusCodeSame(404, 'Story réservée aux abonnés.');

        // alice suit camille (abonnement reçu par la fédération).
        $em = self::getContainer()->get('doctrine')->getManager();
        $alice = self::getContainer()->get(AccountRepository::class)->findOneBy(['username' => 'alice', 'domain' => 'remote.test']);
        $following = self::getContainer()->get(AccountRepository::class)->findLocal('camille');
        self::assertInstanceOf(Account::class, $alice);
        self::assertInstanceOf(Account::class, $following);
        $em->persist(new Follow($alice, $following));
        $em->flush();

        $this->client->request('GET', '/');
        self::assertSelectorExists('.stories a[href="/stories?depuis=camille"]');
        self::assertSelectorNotExists('.stories a[href="/stories/nouvelle"]', 'Pas de story à publier pour un compte Mastodon.');
        $crawler = $this->client->request('GET', '/stories?depuis=camille');
        /** @var array{groups: list<array{stories: list<array{seenUrl: string}>}>, viewToken: string} $data */
        $data = json_decode((string) $crawler->filter('[data-controller="story-viewer"]')->attr('data-story-viewer-data-value'), true, flags: \JSON_THROW_ON_ERROR);
        $this->client->request('POST', $data['groups'][0]['stories'][0]['seenUrl'], ['_token' => $data['viewToken']]);
        self::assertResponseStatusCodeSame(204);
        $em->clear();
        self::assertSame(1, self::getContainer()->get(StoryRepository::class)->findByPublicId($story->getPublicId())?->getViewsCount());
    }

    public function testTheAccountMustBeHostedOnTheServerThatAuthenticatedIt(): void
    {
        // Le serveur annonce l'acteur d'un autre serveur : il est ignoré, le compte est cherché sur le serveur lui-même.
        FakeMastodon::$actor = 'https://ailleurs.test/users/alice';
        $this->connect();
        self::assertSame('alice@remote.test', $this->mastodonUser()->getAccount()->getAcct());
    }

    public function testAnInvalidStateOrAnotherServerIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter avec remote.test')->form());
        $this->client->request('GET', '/connexion/mastodon/retour?'.http_build_query(['code' => FakeMastodon::CODE, 'state' => 'falsifie']));
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'interrompue ou expirée');
        self::assertSame([], FakeMastodon::requestsTo('POST', '/oauth/token'));

        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter avec remote.test')->form(), ['server' => '@bob@autre.test']);
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'n’est pas ouverte sur cette instance');
        self::assertSame([], self::getContainer()->get(MastodonUserRepository::class)->findAll());
    }
}
