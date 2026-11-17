<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\MediaFilter;
use App\Core\Domain\Account\Theme;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

final class AppearanceTest extends AppWebTestCase
{
    public function testVisitorsGetTheAutomaticTheme(): void
    {
        $this->register('alice');
        $this->client->request('GET', '/local');

        self::assertSelectorExists('html[data-theme="auto"]');
        self::assertSelectorExists('html[data-media-filter="light"]', 'Filtre des médias léger par défaut.');
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
        self::assertSelectorExists('form[data-controller="appearance-preview"] input[value="dark"][data-action="change->appearance-preview#preview"][data-appearance-preview-attr-param="theme"]', 'Aperçu du thème avant d’enregistrer.');

        $this->client->submitForm('Enregistrer', ['appearance_settings[theme]' => 'dark']);
        self::assertResponseRedirects('/reglages/apparence');

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('alice@exemple.test');
        self::assertSame(Theme::Dark, $user?->getTheme());

        $this->client->followRedirect();
        self::assertSelectorExists('html[data-theme="dark"]');
        $this->client->request('GET', '/');
        self::assertSelectorExists('html[data-theme="dark"]', 'Le thème s’applique à toutes les pages.');
    }

    public function testTheMediaFilterIsChosenInTheSettingsWithAPreview(): void
    {
        $alice = $this->login($this->register('alice'));
        $this->client->request('GET', '/reglages/apparence');
        self::assertSelectorNotExists('.filter-samples', 'Pas d’aperçu sans publication.');

        self::getContainer()->get(PostComposer::class)->publish($alice->getAccount(), 'Photo', [['file' => $this->jpeg(40, 50), 'alt' => null]]);
        $this->client->request('GET', '/reglages/apparence');
        self::assertSelectorExists('html[data-media-filter="light"]');
        self::assertCheckboxChecked('appearance_settings[mediaFilter]', 'light');
        self::assertSelectorExists('input[name="appearance_settings[mediaFilter]"][value="strong"][data-appearance-preview-attr-param="mediaFilter"]');
        self::assertCount(1, $this->client->getCrawler()->filter('.filter-samples .gt img'), 'Aperçu sur ses dernières publications.');

        $this->client->submitForm('Enregistrer', ['appearance_settings[mediaFilter]' => 'strong']);
        self::assertResponseRedirects('/reglages/apparence');
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('alice@exemple.test');
        self::assertSame(MediaFilter::Strong, $user?->getMediaFilter());
        self::assertSame(Theme::Auto, $user->getTheme(), 'Le thème n’est pas touché.');

        $this->client->followRedirect();
        self::assertSelectorExists('html[data-media-filter="strong"]');

        $this->client->submitForm('Enregistrer', ['appearance_settings[mediaFilter]' => 'off']);
        $this->client->request('GET', '/');
        self::assertSelectorExists('html[data-media-filter="off"]');
    }
}
