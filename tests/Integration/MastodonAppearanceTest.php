<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Account\MediaFilter;
use App\Core\Domain\Account\Theme;
use App\Tests\AppWebTestCase;

/**
 * Compte connecté avec Mastodon : il choisit ici son apparence (thème, filtre des médias), comme un compte de
 * l'instance ; ses autres réglages restent sur son serveur.
 */
final class MastodonAppearanceTest extends AppWebTestCase
{
    use ConnectsWithMastodon;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
    }

    public function testAMastodonAccountChoosesItsAppearance(): void
    {
        $this->connect();

        // Le menu mène à l'apparence ; la page ne propose que cet onglet.
        $this->client->request('GET', '/');
        self::assertSelectorExists('.side a[href="/reglages/apparence"]');
        self::assertSelectorNotExists('.side a[href="/reglages"]');
        $this->client->request('GET', '/reglages/apparence');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-theme="auto"][data-media-filter="light"]');
        self::assertSelectorCount(1, '.ptabs a');

        $this->client->submitForm('Enregistrer', ['appearance_settings[theme]' => 'dark', 'appearance_settings[mediaFilter]' => 'strong']);
        self::assertResponseRedirects('/reglages/apparence');
        $this->client->request('GET', '/');
        self::assertSelectorExists('html[data-theme="dark"][data-media-filter="strong"]', 'Appliqués à toutes les pages.');
        $user = $this->mastodonUser();
        self::assertSame(Theme::Dark, $user->getTheme());
        self::assertSame(MediaFilter::Strong, $user->getMediaFilter());

        // Gardés d'une connexion à l'autre.
        $this->client->request('GET', '/deconnexion');
        $this->connect();
        $this->client->request('GET', '/');
        self::assertSelectorExists('html[data-theme="dark"][data-media-filter="strong"]');

        // Les autres réglages restent fermés.
        foreach (['/reglages', '/reglages/mot-de-passe', '/reglages/groupes'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
    }
}
