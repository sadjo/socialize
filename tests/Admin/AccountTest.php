<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Core\Application\Account\Invitations;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Tests\AppWebTestCase;

final class AccountTest extends AppWebTestCase
{
    public function testTheAdministratorCreatesAnAccountThatCanLogIn(): void
    {
        $admin = $this->register('alice');
        $member = $this->register('hugo');

        $this->login($member);
        $this->client->request('GET', '/admin/comptes');
        self::assertResponseStatusCodeSame(403);

        $this->login($admin);
        $crawler = $this->client->request('GET', '/admin/comptes');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.ptabs a[href="/admin/comptes"].on');
        self::assertSelectorExists('.ptabs a[href="/admin/invitations"]');
        self::assertSelectorTextContains('table.accounts', '@hugo');
        self::assertSelectorTextContains('table.accounts', 'Administrateur');

        $this->client->submit($crawler->selectButton('Créer le compte')->form([
            'account[email]' => 'lina@exemple.fr',
            'account[username]' => 'lina',
            'account[displayName]' => 'Lina Morel',
            'account[password][first]' => 'mot-de-passe-solide',
            'account[password][second]' => 'mot-de-passe-solide',
        ]));
        self::assertResponseRedirects('/admin/comptes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Compte @lina créé.');
        self::assertSelectorTextContains('table.accounts', 'lina@exemple.fr');

        $lina = self::getContainer()->get(UserRepository::class)->findOneByEmail('lina@exemple.fr');
        self::assertInstanceOf(User::class, $lina);
        self::assertFalse($lina->isAdmin());
        self::assertSame('Lina Morel', $lina->getAccount()->getDisplayName());
        self::assertSame($admin->getId()->toRfc4122(), $this->currentUserId(), 'L’administrateur reste connecté sous son compte.');

        // La personne se connecte avec les identifiants transmis.
        $this->client->request('GET', '/deconnexion');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifier' => 'lina', 'password' => 'mot-de-passe-solide']));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('nav.side a[href="/@lina"]');
    }

    public function testDuplicatesAndInvalidValuesAreExplained(): void
    {
        $this->login($this->register('alice'));
        $this->register('hugo');
        $crawler = $this->client->request('GET', '/admin/comptes');
        $this->client->submit($crawler->selectButton('Créer le compte')->form([
            'account[email]' => 'autre@exemple.fr',
            'account[username]' => 'hugo',
            'account[password][first]' => 'mot-de-passe-solide',
            'account[password][second]' => 'mot-de-passe-solide',
        ]));
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('.form-errors', 'Ce nom d’utilisateur est déjà pris.');

        $crawler = $this->client->request('GET', '/admin/comptes');
        $this->client->submit($crawler->selectButton('Créer le compte')->form([
            'account[email]' => 'lina@exemple.fr',
            'account[username]' => 'Lina Morel',
            'account[password][first]' => '',
            'account[password][second]' => '',
        ]));
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('main', 'Utilisez seulement des lettres minuscules');
        self::assertSelectorTextContains('main', 'Saisissez un mot de passe.');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('lina@exemple.fr'));
    }

    public function testOnlyTheAdministratorMayChooseAShortPassword(): void
    {
        $this->login($this->register('alice'));
        $crawler = $this->client->request('GET', '/admin/comptes');
        $this->client->submit($crawler->selectButton('Créer le compte')->form([
            'account[email]' => 'papi@exemple.fr',
            'account[username]' => 'papi',
            'account[password][first]' => '1234',
            'account[password][second]' => '1234',
        ]));
        self::assertResponseRedirects('/admin/comptes', message: 'Mot de passe court accepté pour un compte créé par l’administrateur.');

        $this->client->request('GET', '/deconnexion');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifier' => 'papi', 'password' => '1234']));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('nav.side a[href="/@papi"]');

        // L'inscription (ici par invitation), elle, garde ses 10 caractères minimum.
        $admin = self::getContainer()->get(UserRepository::class)->findOneByEmail('alice@exemple.test');
        self::assertInstanceOf(User::class, $admin);
        ['token' => $token] = self::getContainer()->get(Invitations::class)->create($admin->getAccount());
        $this->client->request('GET', '/deconnexion');
        $crawler = $this->client->request('GET', '/invitation/'.$token);
        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration[email]' => 'mamie@exemple.fr',
            'registration[username]' => 'mamie',
            'registration[password][first]' => '1234',
            'registration[password][second]' => '1234',
        ]));
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('main', 'au moins 10 caractères');
    }

    private function currentUserId(): ?string
    {
        $user = self::getContainer()->get('security.token_storage')->getToken()?->getUser();

        return $user instanceof User ? $user->getId()->toRfc4122() : null;
    }
}
