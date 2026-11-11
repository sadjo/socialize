<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;

final class VersionTest extends AppWebTestCase
{
    public function testTheVersionIsShownAtTheBottomOfTheMenu(): void
    {
        $this->client->request('GET', '/connexion');
        self::assertSelectorTextSame('nav.side > .side-version:last-child', 'dev');
        self::assertSame(
            'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover',
            $this->client->getCrawler()->filter('meta[name="viewport"]')->attr('content'),
            'Zoom fixe sur mobile.',
        );

        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextSame('nav.side > .side-version:last-child', 'dev');
        self::assertSame('Déconnexion', $crawler->filter('.side-version')->previousAll()->first()->text(), 'Sous le lien de déconnexion.');
    }
}
