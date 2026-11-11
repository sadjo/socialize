<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;

/**
 * Instance réservée aux comptes (temporaire, HOME_LOGIN_ONLY=1).
 */
final class HomeLoginTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        $_SERVER['HOME_LOGIN_ONLY'] = $_ENV['HOME_LOGIN_ONLY'] = '1';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_SERVER['HOME_LOGIN_ONLY'] = $_ENV['HOME_LOGIN_ONLY'] = '0';
        parent::tearDown();
    }

    public function testVisitorsSeeOnlyTheLoginFormOnTheHomePage(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main.auth-page form[action="/connexion"] input[name="identifier"]');
        self::assertSelectorNotExists('nav.side', 'Pas de menu de gauche.');
        self::assertSelectorNotExists('header.mtop');
        self::assertSelectorTextSame('main.auth-page > .auth-version', 'dev', 'Version sous le formulaire.');

        $this->register('camille', password: 'mot-de-passe-solide');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifier' => 'camille', 'password' => 'mot-de-passe-solide']));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('nav.side', 'Connecté : l’accueil habituel, avec le menu.');
    }

    public function testPublicFeedsAndRegistrationAreClosedToVisitors(): void
    {
        foreach (['/local', '/federe', '/inscription'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/', message: $path);
        }

        // Profil public consultable, sans lien vers les fils publics ni vers l'inscription.
        $camille = $this->register('camille');
        $this->client->request('GET', '/@camille');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/local"]');
        self::assertSelectorNotExists('a[href="/inscription"]');
        self::assertSelectorExists('nav.side a[href="/connexion"]');

        $this->login($camille);
        $this->client->request('GET', '/local');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.side a[href="/local"]', 'Connecté : fils publics accessibles.');
        $this->client->request('GET', '/federe');
        self::assertResponseIsSuccessful();
    }
}
