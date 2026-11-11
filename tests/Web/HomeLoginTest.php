<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;

final class HomeLoginTest extends AppWebTestCase
{
    public function testVisitorsSeeOnlyTheLoginFormOnTheHomePage(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main.auth-page form[action="/connexion"] input[name="identifier"]');
        self::assertSelectorNotExists('nav.side', 'Pas de menu de gauche.');
        self::assertSelectorNotExists('header.mtop');

        $this->register('camille', password: 'mot-de-passe-solide');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifier' => 'camille', 'password' => 'mot-de-passe-solide']));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('nav.side', 'Connecté : l’accueil habituel, avec le menu.');
    }
}
