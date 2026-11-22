<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Tests\AppWebTestCase;

final class AccountTest extends AppWebTestCase
{
    public function testTheFirstAccountBecomesAdministrator(): void
    {
        $this->client->request('GET', '/inscription');
        self::assertSelectorTextContains('.note', 'première personne');

        $this->client->submitForm('Créer mon compte', [
            'registration[email]' => 'Camille@Exemple.test',
            'registration[username]' => 'camille',
            'registration[displayName]' => 'Camille Roux',
            'registration[password][first]' => 'un-mot-de-passe-long',
            'registration[password][second]' => 'un-mot-de-passe-long',
        ]);

        self::assertResponseRedirects('/publier');
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('camille@exemple.test');
        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
        self::assertSame('Camille Roux', $user->getAccount()->getDisplayName());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouvelle publication');
    }

    public function testRegistrationIsByInvitationOnceTheInstanceHasAnAdministrator(): void
    {
        $this->register('camille');

        $this->client->request('GET', '/inscription');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'Inscriptions sur invitation');
    }

    public function testClosedRegistrationAlsoBlocksTheFirstAccount(): void
    {
        $_SERVER['REGISTRATION'] = $_ENV['REGISTRATION'] = 'closed';
        try {
            self::ensureKernelShutdown();
            $client = self::createClient();
            $client->request('GET', '/inscription');

            self::assertResponseStatusCodeSame(403);
            self::assertSelectorTextContains('h1', 'Inscriptions fermées');
        } finally {
            $_SERVER['REGISTRATION'] = $_ENV['REGISTRATION'] = 'invite';
        }
    }

    public function testRegistrationRejectsAnInvalidUsername(): void
    {
        $this->client->request('GET', '/inscription');
        $this->client->submitForm('Créer mon compte', [
            'registration[email]' => 'camille@exemple.test',
            'registration[username]' => 'Camille Roux!',
            'registration[password][first]' => 'un-mot-de-passe-long',
            'registration[password][second]' => 'un-mot-de-passe-long',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors', 'lettres minuscules');
    }

    public function testLoginWithEmailAndPassword(): void
    {
        $this->register('camille', 'camille@exemple.test', 'un-mot-de-passe-long');

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['identifier' => 'camille@exemple.test', 'password' => 'un-mot-de-passe-long']);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('nav.side a.nav.on', 'Publications', 'L’accueil s’appelle « Publications » dans le menu.');
        self::assertSelectorExists('nav.side a.nav.on use[href="#i-image"]');
        self::assertSelectorExists('.tabbar a.on[aria-label="Publications"]');
        self::assertSelectorTextContains('.seg a.on[href="/"]', 'Abonnements', 'Onglets des comptes suivis sur Publications.');
        self::assertSelectorTextContains('.seg a[href="/?fil=instance"]', 'Instance');
        self::assertSelectorTextContains('.seg a[href="/?fil=federe"]', 'Fédéré');

        $this->client->request('GET', '/local');
        self::assertSelectorNotExists('.seg', 'Pas d’onglets sur le fil local.');
        self::assertSelectorTextContains('nav.side a.nav.on', 'Local', '« Local » est inchangé dans le menu.');
    }

    public function testLoginWithTheUsername(): void
    {
        $this->register('camille', 'camille@exemple.test', 'un-mot-de-passe-long');

        foreach (['camille', '@Camille'] as $identifier) {
            $this->client->request('GET', '/deconnexion');
            $this->client->request('GET', '/connexion');
            $this->client->submitForm('Se connecter', ['identifier' => $identifier, 'password' => 'un-mot-de-passe-long']);

            self::assertResponseRedirects('/', message: $identifier);
        }
    }

    public function testLoginShowsAFrenchErrorForAWrongPassword(): void
    {
        $this->register('camille', 'camille@exemple.test');

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['identifier' => 'camille@exemple.test', 'password' => 'mauvais']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.form-errors', 'Identifiants invalides');
    }

    public function testComposeRequiresALoggedInAccount(): void
    {
        $this->client->request('GET', '/publier');

        self::assertResponseRedirects('/connexion');
    }

    public function testTheProfileCanBeEdited(): void
    {
        $this->login($this->register('camille'));

        $this->client->request('GET', '/reglages');
        $this->client->submitForm('Enregistrer', [
            'profile_settings[displayName]' => 'Camille R.',
            'profile_settings[bio]' => 'Photographe entre mer et montagne. #normandie',
            'profile_settings[avatar]' => $this->jpeg(600, 600, 'avatar.jpg'),
        ]);

        self::assertResponseRedirects('/reglages');
        $this->client->request('GET', '/@camille');
        self::assertSelectorTextContains('.bio', 'Camille R.');
        self::assertSelectorExists('.bio .tag');
        self::assertSelectorExists('.prof-h img.avatar-img');
    }
}
