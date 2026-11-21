<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;

/**
 * Profil public (visiteur non connecté) : ni menu ni logo ni boutons Suivre et Connexion ; nom d'affichage, nom
 * d'utilisateur, adresse à copier, adresse dans le fédivers ; « Propulsé par » en pied de page.
 */
final class PublicProfileTest extends AppWebTestCase
{
    public function testAVisitorSeesAPlainProfile(): void
    {
        $this->register('camille');
        $crawler = $this->client->request('GET', '/@camille');
        self::assertResponseIsSuccessful();

        foreach (['header.mtop', 'nav.side', 'nav.tabbar', '.logo'] as $hidden) {
            self::assertSelectorNotExists($hidden, $hidden);
        }
        self::assertSelectorNotExists('a[href="/connexion"]');
        self::assertSelectorNotExists('form[action$="/suivre"]');
        self::assertSelectorTextNotContains('.prof-h', 'Suivre');

        $info = $crawler->filter('.prof-public');
        self::assertSame('Camille', $info->filter('h1')->text());
        self::assertSame('camille', $info->filter('.uname')->text());
        self::assertSame('http://localhost/@camille', $info->filter('button[data-controller="copy"]')->attr('data-copy-text-value'));
        self::assertStringContainsString('@camille@', $info->filter('.handle')->text());
        // Dans cet ordre : nom d'affichage, nom d'utilisateur, bouton, adresse dans le fédivers.
        $order = $info->filter('h1, .uname, button[data-controller="copy"], .handle')->each(static fn ($n): string => $n->nodeName().'.'.$n->attr('class'));
        self::assertSame(['h1.', 'p.uname', 'button.btn sm', 'span.handle'], $order);

        self::assertSelectorTextSame('footer.powered', 'Propulsé par socialize · Aide · Version dev');
        self::assertSelectorExists('footer.powered a[href="https://sadjo.github.io/socialize/"]');
    }

    public function testAConnectedVisitorKeepsTheMenuAndTheFollowButton(): void
    {
        $this->register('camille');
        $this->login($this->register('hugo'));
        $this->client->request('GET', '/@camille');
        self::assertSelectorExists('nav.side');
        self::assertSelectorExists('header.mtop');
        self::assertSelectorExists('form[action="/@camille/suivre"]');
        self::assertSelectorNotExists('footer.powered');
        self::assertSelectorNotExists('.prof-public');
    }
}
