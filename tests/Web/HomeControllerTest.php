<?php

declare(strict_types=1);

namespace App\Tests\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageShowsTheInstanceStatus(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le socle fonctionne');
        self::assertSelectorTextContains('.status', 'Connectée');
    }
}
