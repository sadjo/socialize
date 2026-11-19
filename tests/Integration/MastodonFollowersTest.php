<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Notification\NotificationType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\Follow;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\NotificationRepository;
use App\Federation\Application\ApUrls;
use App\Federation\Application\RemoteAccounts;
use App\Tests\AppWebTestCase;
use App\Tests\Federation\FakeFediverse;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compte Mastodon connecté ici : ses abonnés se gèrent comme ceux d'un compte d'ici (demandes acceptées ou refusées,
 * abonnés retirés), par l'API de son serveur, qui prévient l'abonné par la fédération ; et il reçoit ici les mêmes
 * notifications qu'un compte d'ici.
 */
final class MastodonFollowersTest extends AppWebTestCase
{
    use ConnectsWithMastodon;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
        FakeFediverse::$outbox = [];
        FakeFediverse::$actorExtra = [];
    }

    protected function tearDown(): void
    {
        FakeFediverse::$actorExtra = [];
        parent::tearDown();
    }

    /**
     * Compte verrouillé sur son serveur (manuallyApprovesFollowers) : ses nouveaux abonnés attendent son accord.
     */
    public function testTheFollowApprovalOfARemoteAccountIsReadFromItsActor(): void
    {
        $remote = self::getContainer()->get(RemoteAccounts::class);
        FakeFediverse::$actorExtra = ['manuallyApprovesFollowers' => true];
        self::assertTrue($remote->byUri(FakeFediverse::ALICE, refresh: true)->manuallyApprovesFollowers());
        FakeFediverse::$actorExtra = [];
        self::assertFalse($remote->byUri(FakeFediverse::ALICE, refresh: true)->manuallyApprovesFollowers());
    }

    public function testAFollowRequestIsAcceptedThenTheFollowerRemovedThroughTheMastodonServer(): void
    {
        $this->register('camille');
        $this->connect();
        $camille = $this->camille();
        $this->lockAlice();
        self::getContainer()->get(SocialActions::class)->follow($this->camille(), $this->alice());
        $camilleId = FakeMastodon::idOf(self::getContainer()->get(ApUrls::class)->actor($camille));

        // Notification de la demande, et lien vers les notifications dans le menu.
        $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notif.unread', 'demande à s’abonner à votre compte');
        self::assertSelectorExists('nav.side a.notif-link[href="/notifications"] .badge');
        self::assertSelectorNotExists('.push-card', 'Pas de notifications sur l’appareil : son application Mastodon le prévient.');

        // Accepter : par son serveur, qui enverra l'Accept ; la notification disparaît.
        $crawler = $this->client->request('GET', '/abonnes/demandes');
        $this->client->submit($crawler->filter('form[action="/@camille/accepter-l-abonnement"]')->form());
        self::assertResponseRedirects('/abonnes/demandes');
        self::assertCount(1, FakeMastodon::requestsTo('POST', '/api/v1/follow_requests/'.$camilleId.'/authorize'));
        self::assertTrue($this->follow()?->isAccepted());
        self::assertSame(0, $this->requestNotifications());

        // Retirer l'abonnée : par son serveur aussi.
        $crawler = $this->client->request('GET', '/@alice@remote.test/abonnes');
        $this->client->submit($crawler->filter('form[action="/@camille/retirer-des-abonnes"]')->form());
        self::assertCount(1, FakeMastodon::requestsTo('POST', '/api/v1/accounts/'.$camilleId.'/remove_from_followers'));
        self::assertNull($this->follow());
    }

    public function testAFollowRequestIsRefusedThroughTheMastodonServer(): void
    {
        $this->register('camille');
        $this->connect();
        $camille = $this->camille();
        $this->lockAlice();
        self::getContainer()->get(SocialActions::class)->follow($this->camille(), $this->alice());

        $crawler = $this->client->request('GET', '/abonnes/demandes');
        $this->client->submit($crawler->filter('form[action="/@camille/retirer-des-abonnes"]')->form());
        self::assertCount(1, FakeMastodon::requestsTo('POST', '/api/v1/follow_requests/'.FakeMastodon::idOf(self::getContainer()->get(ApUrls::class)->actor($camille)).'/reject'));
        self::assertNull($this->follow());
        self::assertSame(0, $this->requestNotifications());
    }

    /**
     * Compte qui ne valide pas ses abonnés : l'abonnement attend l'Accept de son serveur, sans demande à traiter.
     */
    public function testNoFollowRequestNotificationForAnUnlockedMastodonAccount(): void
    {
        $this->register('camille');
        $this->connect();
        $camille = $this->camille();
        self::getContainer()->get(SocialActions::class)->follow($camille, $this->alice());

        self::assertSame(0, $this->requestNotifications());
    }

    public function testAMastodonAccountIsNotifiedOfLikesAndCommentsFromHere(): void
    {
        $this->register('camille');
        $this->connect();
        $camille = $this->camille();
        $post = Post::federated($this->alice(), 'Au marché', Visibility::Public, null, 'https://remote.test/users/alice/statuses/1', 'https://remote.test/@alice/1', new \DateTimeImmutable());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($post);
        $em->flush();

        $social = self::getContainer()->get(SocialActions::class);
        $social->toggleLike($camille, $post);
        $social->comment($camille, $post, 'Superbe !');

        $this->client->request('GET', '/notifications');
        self::assertSelectorCount(2, '.notif.unread');
        self::assertSelectorTextContains('.notifs', 'a aimé votre publication');
        self::assertSelectorTextContains('.notifs', 'a commenté votre publication : « Superbe ! »');

        // Les notifications se marquent comme lues et se suppriment ici.
        $this->client->submit($this->client->getCrawler()->selectButton('Tout marquer comme lu')->form());
        self::assertResponseRedirects();
        $this->client->request('GET', '/notifications');
        self::assertSelectorNotExists('.notif.unread');
        $this->client->submit($this->client->getCrawler()->selectButton('Tout supprimer')->form());
        $this->client->request('GET', '/notifications');
        self::assertSelectorNotExists('.notif');
    }

    private function lockAlice(): void
    {
        $alice = $this->alice();
        $alice->requireFollowApproval(true);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function follow(): ?Follow
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(FollowRepository::class)->findBetween($this->camille(), $this->alice());
    }

    private function requestNotifications(): int
    {
        return self::getContainer()->get(NotificationRepository::class)->count(['recipient' => $this->alice(), 'type' => NotificationType::FollowRequest]);
    }

    private function camille(): Account
    {
        $camille = self::getContainer()->get(AccountRepository::class)->findLocal('camille');
        self::assertInstanceOf(Account::class, $camille);

        return $camille;
    }

    private function alice(): Account
    {
        $alice = self::getContainer()->get(AccountRepository::class)->findOneBy(['username' => 'alice', 'domain' => 'remote.test']);
        self::assertInstanceOf(Account::class, $alice);

        return $alice;
    }
}
