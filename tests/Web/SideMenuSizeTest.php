<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Menu de gauche réductible : le bouton « Réduire le menu » mémorise le choix de l'appareil dans le cookie « menu »,
 * relu au rendu de chaque page (attribut data-menu de <html>, dont dépendent les styles).
 */
final class SideMenuSizeTest extends AppWebTestCase
{
    public function testTheMenuIsLargeByDefaultAndOffersToReduceIt(): void
    {
        $this->login($this->register('hugo'));
        $this->client->request('GET', '/');

        self::assertSelectorExists('html[data-menu="large"]');
        self::assertSelectorExists('nav.side button.menu-size[data-action="menu-size#toggle"]');
        self::assertSelectorTextContains('nav.side button.menu-size .when-large', 'Réduire le menu');
        self::assertSelectorTextContains('nav.side button.menu-size .when-reduced', 'Agrandir');
    }

    public function testTheReducedMenuIsKeptFromOnePageToTheNext(): void
    {
        $this->login($this->register('hugo'));
        $this->client->getCookieJar()->set(new Cookie('menu', 'reduit'));

        foreach (['/', '/local', '/@hugo'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorExists('html[data-menu="reduit"]', $page);
        }

        // Valeur inconnue : menu large.
        $this->client->getCookieJar()->set(new Cookie('menu', 'autre'));
        $this->client->request('GET', '/');
        self::assertSelectorExists('html[data-menu="large"]');
    }

    public function testTheReducedMenuMakesRoomForTheSuggestionsOnALargeTabletInPortrait(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');

        self::assertStringContainsString(
            '@media (min-width:1024px) and (max-width:1199px){'."\n".'  :root[data-menu="reduit"] .home{grid-template-columns:minmax(0,480px) 300px;gap:32px}'."\n".'  :root[data-menu="reduit"] .rail{display:flex}',
            $css,
        );
        self::assertStringContainsString(':root[data-menu="reduit"]{--side-w:84px}', $css);
    }
}
