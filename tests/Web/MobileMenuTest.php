<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;

/**
 * Sur mobile, le menu de gauche est masqué : le bouton « Menu » de la barre du haut donne accès à tout le reste.
 */
final class MobileMenuTest extends AppWebTestCase
{
    public function testTheMobileMenuOffersEveryEntryOfTheSideMenu(): void
    {
        $admin = $this->register('alice');
        $member = $this->register('hugo');

        $this->login($member);
        $this->client->request('GET', '/');
        self::assertSelectorExists('header.mtop button[data-action="mobile-menu#open"]');
        self::assertSelectorExists('dialog.mobile-menu button[popovertarget="create-menu"]', 'Créer : choix publication ou story.');
        self::assertSelectorExists('nav.side button[popovertarget="create-menu"]');
        foreach (['/deconnexion', '/recherche', '/import', '/reglages', '/@hugo'] as $href) {
            self::assertSelectorExists(\sprintf('dialog.mobile-menu a[href="%s"]', $href), $href);
            self::assertSelectorExists(\sprintf('nav.side a[href="%s"]', $href), 'Menu de gauche inchangé : '.$href);
        }
        self::assertSelectorExists('dialog.mobile-menu button[data-controller="theme"]');
        self::assertSelectorTextSame('dialog.mobile-menu .side-version', 'dev');
        self::assertSelectorNotExists('dialog.mobile-menu a[href="/admin/comptes"]');

        $this->login($admin);
        $this->client->request('GET', '/');
        self::assertSelectorExists('dialog.mobile-menu a[href="/admin/comptes"]', 'Administration pour l’administrateur.');
    }

    public function testVisitorsKeepTheirLoginButton(): void
    {
        $this->register('alice');
        $this->client->request('GET', '/@alice');
        self::assertSelectorNotExists('dialog.mobile-menu');
        self::assertSelectorExists('header.mtop a.btn');
    }
}
