<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\Theme;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Tests\AppWebTestCase;

final class AppearanceTest extends AppWebTestCase
{
    public function testVisitorsGetTheAutomaticTheme(): void
    {
        $this->register('alice');
        $this->client->request('GET', '/local');

        self::assertSelectorExists('html[data-theme="auto"]');
        self::assertSelectorNotExists('nav.side button[data-controller="theme"]', 'Plus de bascule de thème dans le menu.');
    }

    public function testTheThemeIsChosenInTheSettingsAndFollowsTheUser(): void
    {
        $this->login($this->register('alice'));

        $this->client->request('GET', '/reglages');
        self::assertSelectorExists('nav.ptabs a[href="/reglages/apparence"]');

        $this->client->request('GET', '/reglages/apparence');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-theme="auto"]');
        self::assertCheckboxChecked('appearance_settings[theme]', 'auto');
        self::assertSelectorTextContains('.themes', 'façon Instagram');

        $this->client->submitForm('Enregistrer', ['appearance_settings[theme]' => 'dark']);
        self::assertResponseRedirects('/reglages/apparence');

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('alice@exemple.test');
        self::assertSame(Theme::Dark, $user?->getTheme());

        $this->client->followRedirect();
        self::assertSelectorExists('html[data-theme="dark"]');
        $this->client->request('GET', '/');
        self::assertSelectorExists('html[data-theme="dark"]', 'Le thème s’applique à toutes les pages.');
    }
}
