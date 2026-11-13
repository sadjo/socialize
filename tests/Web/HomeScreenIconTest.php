<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;

/**
 * Icône de l'écran d'accueil : apple-touch-icon pour iPhone et iPad, manifeste avec ses icônes pour Android.
 */
final class HomeScreenIconTest extends AppWebTestCase
{
    public function testEveryPageDeclaresTheHomeScreenIcons(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        self::assertSame('/icons/apple-touch-icon.png', $crawler->filter('link[rel="apple-touch-icon"]')->attr('href'));
        self::assertSame('/site.webmanifest', $crawler->filter('link[rel="manifest"]')->attr('href'));
        self::assertSame('Socialize', $crawler->filter('meta[name="apple-mobile-web-app-title"]')->attr('content'));
    }

    public function testTheManifestListsRealIconsOfTheRightSize(): void
    {
        $public = \dirname(__DIR__, 2).'/public';
        $manifest = json_decode((string) file_get_contents($public.'/site.webmanifest'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('Socialize', $manifest['name'] ?? null);
        self::assertSame('/', $manifest['start_url'] ?? null);
        self::assertIsArray($manifest['icons'] ?? null);

        $purposes = [];
        foreach ($manifest['icons'] as $icon) {
            self::assertIsArray($icon);
            self::assertIsString($icon['src'] ?? null);
            $size = getimagesize($public.$icon['src']);
            self::assertIsArray($size, $icon['src']);
            self::assertSame(\IMAGETYPE_PNG, $size[2]);
            self::assertSame($icon['sizes'], $size[0].'x'.$size[1], $icon['src']);
            $purposes[] = $icon['purpose'];
        }
        self::assertContains('maskable', $purposes, 'Android découpe l’icône à sa forme.');
        self::assertSame([180, 180], \array_slice((array) getimagesize($public.'/icons/apple-touch-icon.png'), 0, 2), 'Taille attendue par iOS.');
    }
}
