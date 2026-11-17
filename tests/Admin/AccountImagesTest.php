<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Federation\Application\RemoteAccounts;
use App\Tests\AppWebTestCase;
use App\Tests\Federation\FakeFediverse;
use App\Tests\Integration\ConnectsWithMastodon;
use App\Tests\Integration\FakeMastodon;

/**
 * L'administrateur change la photo d'un compte de l'instance, ou remplace celle d'un compte Mastodon connecté
 * (sans toucher à son serveur), puis peut rétablir celle de Mastodon.
 */
final class AccountImagesTest extends AppWebTestCase
{
    use ConnectsWithMastodon;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
    }

    public function testTheAdministratorChangesThePhotoOfALocalAccount(): void
    {
        $camille = $this->register('camille');
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/comptes');
        $this->client->click($crawler->filter('a[aria-label="Changer la photo de @camille"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('label.pick-avatar[for="account_images_avatar"]', 'La photo se change d’un clic.');
        $this->client->submitForm('Enregistrer', ['account_images[avatar]' => $this->jpeg(600, 600, 'avatar.jpg')]);
        self::assertResponseRedirects('/admin/comptes');

        $this->client->request('GET', '/@camille');
        self::assertStringContainsString('/avatars/', (string) $this->client->getCrawler()->filter('.prof-h img.avatar-img')->attr('src'));
        self::assertNotNull($this->account($camille->getAccount()->getId()->toRfc4122())->getAvatarPath());
    }

    public function testTheAdministratorOverridesThenRestoresTheMastodonPhoto(): void
    {
        $this->connect();
        $alice = $this->mastodonUser()->getAccount();
        $id = $alice->getId()->toRfc4122();
        self::assertSame('https://remote.test/avatars/alice.png', $alice->getAvatarUrl());
        $this->client->request('GET', '/deconnexion');

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/admin/comptes');
        $this->client->click($crawler->filter('a[aria-label="Changer la photo de @alice@remote.test"]')->link());
        self::assertSelectorNotExists('#account_images_removeAvatar', 'Rien à rétablir : la photo n’est pas encore remplacée.');
        $this->client->submitForm('Enregistrer', ['account_images[avatar]' => $this->jpeg(600, 600, 'avatar.jpg')]);
        self::assertResponseRedirects('/admin/comptes');

        $this->client->request('GET', '/@alice@remote.test');
        self::assertStringContainsString('/avatars/', (string) $this->client->getCrawler()->filter('.prof-h img.avatar-img')->attr('src'), 'Photo remplacée sur Socialize.');
        self::assertSame('https://remote.test/headers/alice.png', $this->client->getCrawler()->filter('.prof-cover img')->attr('src'), 'Couverture venue de Mastodon.');

        self::getContainer()->get(RemoteAccounts::class)->byUri(FakeFediverse::ALICE, refresh: true);
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNotNull($this->account($id)->getAvatarPath(), 'Un rafraîchissement depuis Mastodon garde la photo remplacée.');

        $this->client->request('GET', '/admin/comptes/'.$id.'/images');
        self::assertSelectorTextContains('.card', 'Photo de profil remplacée ici.');
        self::assertSelectorTextContains('label.chk', 'Rétablir la photo de Mastodon');
        $this->client->submitForm('Enregistrer', ['account_images[removeAvatar]' => true]);
        self::assertResponseRedirects('/admin/comptes');
        $this->client->request('GET', '/@alice@remote.test');
        self::assertSame('https://remote.test/avatars/alice.png', $this->client->getCrawler()->filter('.prof-h img.avatar-img')->attr('src'), 'Photo de Mastodon rétablie.');
    }

    public function testOnlyTheAdministratorAndOnlyManagedAccounts(): void
    {
        $camille = $this->register('camille');
        $remote = Account::remote('bob', 'ailleurs.test', 'https://ailleurs.test/users/bob');
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist($remote);
        $em->flush();

        $this->login($this->register('hugo', publisher: false));
        $this->client->request('GET', '/admin/comptes/'.$camille->getAccount()->getId()->toRfc4122().'/images');
        self::assertResponseStatusCodeSame(403);

        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/comptes/'.$remote->getId()->toRfc4122().'/images');
        self::assertResponseStatusCodeSame(404, 'Un compte du fédivers qui ne s’est pas connecté ici ne se modifie pas.');
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->register('admin');
        $admin->promoteToAdmin();
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->login($admin);
    }

    private function account(string $id): Account
    {
        $account = self::getContainer()->get(AccountRepository::class)->find($id);
        self::assertInstanceOf(Account::class, $account);

        return $account;
    }
}
