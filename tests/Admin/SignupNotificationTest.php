<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Core\Application\Account\Invitations;
use App\Core\Domain\Account\User;
use App\Tests\AppWebTestCase;
use App\Tests\Integration\ConnectsWithMastodon;
use App\Tests\Integration\FakeMastodon;

/**
 * Les administrateurs sont prévenus, dans leurs notifications, des inscriptions et des premières connexions avec
 * Mastodon ; pas des comptes qu'ils créent eux-mêmes, ni les autres comptes.
 */
final class SignupNotificationTest extends AppWebTestCase
{
    use ConnectsWithMastodon;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
    }

    public function testAdministratorsAreNotifiedOfASignupByInvitation(): void
    {
        $alice = $this->admin('alice');
        $other = $this->admin('romain');
        $hugo = $this->register('hugo');
        ['token' => $token] = self::getContainer()->get(Invitations::class)->create($alice->getAccount());

        $crawler = $this->client->request('GET', '/invitation/'.$token);
        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration[email]' => 'lina@exemple.fr',
            'registration[username]' => 'lina',
            'registration[password][first]' => 'mot-de-passe-solide',
            'registration[password][second]' => 'mot-de-passe-solide',
        ]));
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/deconnexion');

        foreach ([$alice, $other] as $admin) {
            $this->login($admin);
            $this->client->request('GET', '/notifications');
            self::assertSelectorTextContains('.notif.unread', 'a créé son compte (@lina)');
            self::assertSelectorExists('.notif .notif-kind.signup');
            self::assertSelectorTextContains('nav.side .notif-link .badge', '1');
        }

        $this->client->submit($this->client->getCrawler()->filter('.notif-open')->first()->form());
        self::assertResponseRedirects('/admin/comptes', message: 'La notification mène à la liste des comptes.');

        $this->login($hugo);
        $this->client->request('GET', '/notifications');
        self::assertSelectorNotExists('.notif', 'Un compte qui n’administre pas n’est pas prévenu.');
    }

    public function testAFirstMastodonConnectionIsNotifiedOnce(): void
    {
        $admin = $this->admin('alice');
        $this->connect();
        $this->client->request('GET', '/deconnexion');
        $this->connect();
        $this->client->request('GET', '/deconnexion');

        $this->login($admin);
        $this->client->request('GET', '/notifications');
        self::assertSelectorCount(1, '.notif', 'Seulement à la première connexion.');
        self::assertSelectorTextContains('.notif', 's’est connecté pour la première fois avec son compte Mastodon (@alice@remote.test)');
        self::assertSelectorTextNotContains('.notif', 'Accordez-lui ses droits', 'Ses droits se règlent sur son serveur.');

        // La notification mène à son profil, pas à l'administration.
        $this->client->submit($this->client->getCrawler()->filter('.notif-open')->first()->form());
        self::assertResponseRedirects('/@alice@remote.test');
    }

    public function testAccountsCreatedByTheAdministratorAreNotNotified(): void
    {
        $admin = $this->admin('alice');
        $this->login($admin);
        $crawler = $this->client->request('GET', '/admin/comptes');
        $this->client->submit($crawler->selectButton('Créer le compte')->form([
            'account[email]' => 'lina@exemple.fr',
            'account[username]' => 'lina',
            'account[password][first]' => 'secret',
            'account[password][second]' => 'secret',
        ]));
        self::assertResponseRedirects('/admin/comptes');

        $this->client->request('GET', '/notifications');
        self::assertSelectorNotExists('.notif');
    }

    private function admin(string $username): User
    {
        $user = $this->register($username);
        $user->promoteToAdmin();
        self::getContainer()->get('doctrine')->getManager()->flush();

        return $user;
    }
}
