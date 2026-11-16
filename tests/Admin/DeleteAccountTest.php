<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Core\Application\Account\AccountDeletion;
use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Account\User;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use App\Tests\Federation\FakeFediverse;
use App\Tests\Integration\ConnectsWithMastodon;
use App\Tests\Integration\FakeMastodon;

/**
 * Suppression des comptes dans l'administration : un compte de l'instance avec tout ce qu'il a publié (et le fédivers
 * prévenu), ou l'accès d'un compte Mastodon connecté.
 */
final class DeleteAccountTest extends AppWebTestCase
{
    use ConnectsWithMastodon;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
        FakeFediverse::$outbox = [];
    }

    public function testAnAdministratorDeletesAnAccountAndEverythingItPublished(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $composer = self::getContainer()->get(PostComposer::class);
        $own = $composer->publish($camille->getAccount(), 'Ma photo', [['file' => $this->jpeg(200, 250), 'alt' => null]], Visibility::Public);
        $other = $composer->publish($hugo->getAccount(), 'Photo de Hugo', [['file' => $this->jpeg(200, 250), 'alt' => null]], Visibility::Public);
        $social = self::getContainer()->get(SocialActions::class);
        $social->toggleLike($camille->getAccount(), $other);
        $social->comment($camille->getAccount(), $other, 'Superbe');
        $original = $own->getMedia()->first() ?: throw new \LogicException();
        $originalPath = $original->getOriginalPath();
        $originals = self::getContainer()->get('originals.storage');
        self::assertTrue($originals->fileExists($originalPath));

        $admin = $this->register('admin');
        $admin->promoteToAdmin();
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->login($admin);
        $crawler = $this->client->request('GET', '/admin/comptes');
        self::assertSelectorNotExists('form[action="/admin/comptes/'.$admin->getId()->toRfc4122().'/supprimer"]', 'Pas de suppression de son propre compte.');
        $this->client->submit($crawler->filter('form[action="/admin/comptes/'.$camille->getId()->toRfc4122().'/supprimer"]')->form());
        self::assertResponseRedirects('/admin/comptes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'Compte @camille supprimé');

        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('camille@exemple.test'));
        self::assertNull(self::getContainer()->get(AccountRepository::class)->findLocal('camille'));
        self::assertNull(self::getContainer()->get(PostRepository::class)->find($own->getId()));
        self::assertFalse(self::getContainer()->get('originals.storage')->fileExists($originalPath), 'Ses fichiers sont effacés.');
        $other = self::getContainer()->get(PostRepository::class)->find($other->getId());
        self::assertInstanceOf(Post::class, $other);
        self::assertSame(0, $other->getLikesCount(), 'Compteurs corrigés.');
        self::assertSame(0, $other->getCommentsCount());

        // Son propre compte : refusé.
        $this->client->request('POST', '/admin/comptes/'.$admin->getId()->toRfc4122().'/supprimer');
        self::assertResponseStatusCodeSame(403, 'Sans jeton valide.');
        self::assertInstanceOf(User::class, self::getContainer()->get(UserRepository::class)->findOneByEmail('admin@exemple.test'));
    }

    public function testTheFediverseIsToldThatTheAccountIsDeleted(): void
    {
        $camille = $this->register('camille');
        $alice = self::getContainer()->get(\App\Federation\Application\RemoteAccounts::class)->byUri(FakeFediverse::ALICE);
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist(new \App\Core\Domain\Social\Follow($alice, $camille->getAccount()));
        $em->flush();

        self::getContainer()->get(AccountDeletion::class)->delete($camille);
        /** @var FakeFediverse $fediverse */
        $fediverse = self::getContainer()->get(FakeFediverse::class);
        $deletes = array_values(array_filter($fediverse->deliveries, static fn (array $d): bool => 'Delete' === ($d['activity']['type'] ?? null)));
        self::assertCount(1, $deletes);
        self::assertSame('https://remote.test/inbox', $deletes[0]['url']);
        self::assertSame('http://localhost/users/camille', $deletes[0]['activity']['object'] ?? null);
        self::assertStringContainsString('keyId="http://localhost/users/camille#main-key"', $deletes[0]['headers']['signature'] ?? '', 'Signée par le compte, avant sa suppression.');
        self::assertInstanceOf(Account::class, self::getContainer()->get(AccountRepository::class)->find($alice->getId()), 'Le compte distant reste.');
    }

    public function testAnAdministratorRemovesTheAccessOfAMastodonAccount(): void
    {
        $this->connect();
        $user = $this->mastodonUser();
        $this->client->request('GET', '/deconnexion');
        $this->connect();

        $admin = $this->register('admin');
        $admin->promoteToAdmin();
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->login($admin);
        $crawler = $this->client->request('GET', '/admin/comptes');
        $this->client->submit($crawler->filter('form[action="/admin/comptes-mastodon/'.$user->getId()->toRfc4122().'/supprimer"]')->form());
        self::assertResponseRedirects('/admin/comptes');
        self::assertCount(2, FakeMastodon::requestsTo('POST', '/oauth/revoke'), 'Jeton révoqué (après la déconnexion du début, puis par la suppression).');
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertSame([], self::getContainer()->get(MastodonUserRepository::class)->findAll());
        self::assertNotNull(self::getContainer()->get(AccountRepository::class)->findOneBy(['username' => 'alice', 'domain' => 'remote.test']), 'Son compte du fédivers reste connu.');
    }
}
