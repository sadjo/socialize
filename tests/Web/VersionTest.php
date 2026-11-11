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

        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextSame('nav.side > .side-version:last-child', 'dev');
        self::assertSame('Déconnexion', $crawler->filter('.side-version')->previousAll()->first()->text(), 'Sous le lien de déconnexion.');
    }
}
