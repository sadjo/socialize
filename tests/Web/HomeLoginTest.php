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
        foreach (['/local', '/federe', '/recherche', '/recherche?q=@alice@remote.test', '/inscription', '/connexion'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/', message: $path);
        }

        // Profil public consultable, sans menu (ni à gauche, ni en bas sur mobile) ni lien vers les pages fermées.
        $camille = $this->register('camille');
        $this->client->request('GET', '/@camille');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.app.no-menu');
        self::assertSelectorNotExists('nav.side');
        self::assertSelectorNotExists('nav.tabbar');
        foreach (['/local', '/federe', '/recherche', '/inscription', '/connexion'] as $closed) {
            self::assertSelectorNotExists(\sprintf('a[href="%s"]', $closed), $closed);
        }
        self::assertSelectorNotExists('header.mtop', 'Profil public : ni logo ni bouton de connexion.');
        self::assertSelectorTextContains('footer.powered', 'Propulsé par socialize.example');

        $this->login($camille);
        $this->client->request('GET', '/local');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.side a[href="/local"]', 'Connecté : fils publics accessibles.');
        $this->client->request('GET', '/federe');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/recherche');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/@camille');
        self::assertSelectorExists('nav.side', 'Connecté : le menu revient sur les profils.');
    }

    public function testALoginErrorIsShownOnTheHomePage(): void
    {
        $this->register('camille', password: 'mot-de-passe-solide');
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifier' => 'camille', 'password' => 'mauvais-mot-de-passe']));
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorExists('main.auth-page .form-errors li');
        self::assertInputValueSame('identifier', 'camille', 'Identifiant prérempli.');

        // Page protégée : renvoi vers la connexion, donc vers l'accueil.
        $this->client->request('GET', '/publier');
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        self::assertResponseRedirects('/');
    }
}
