<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;

/**
 * Boutons « Créer » (menu, barre du haut, barre du bas) : ils ouvrent le choix entre publication et story.
 */
final class CreateMenuTest extends AppWebTestCase
{
    public function testTheCreateButtonsOfferAPostOrAStory(): void
    {
        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('nav.side button[popovertarget="create-menu"]'), 'Menu de gauche.');
        self::assertCount(1, $crawler->filter('header.mtop > button[popovertarget="create-menu"]'), 'Barre du haut (mobile).');
        self::assertCount(1, $crawler->filter('.mobile-menu button.nav[popovertarget="create-menu"]'), 'Menu mobile.');
        self::assertCount(1, $crawler->filter('nav.tabbar button.plus[popovertarget="create-menu"]'), 'Barre du bas (mobile).');

        $menu = $crawler->filter('#create-menu[popover]');
        self::assertCount(1, $menu);
        self::assertSame(['/publier', '/stories/nouvelle'], $menu->filter('a.opt')->each(static fn ($a): ?string => $a->attr('href')));
        self::assertStringContainsString('Publication', $menu->filter('a.opt')->eq(0)->text());
        self::assertStringContainsString('Story', $menu->filter('a.opt')->eq(1)->text());

        $this->client->request('GET', '/stories/nouvelle');
        self::assertResponseIsSuccessful();
    }

    public function testNoCreateButtonWithoutThePublishingRight(): void
    {
        $this->register('admin');
        $this->login($this->register('hugo', publisher: false));
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('[popovertarget="create-menu"]');
        self::assertSelectorNotExists('#create-menu');
    }
}
