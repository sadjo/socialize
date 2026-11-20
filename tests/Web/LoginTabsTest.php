<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;

/**
 * Écran de connexion à deux onglets : Mastodon (serveur et identifiant) et socialize.example (comptes de l'instance).
 */
final class LoginTabsTest extends AppWebTestCase
{
    /** @var array<string, array{server: mixed, env: mixed}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $saved) {
            $_SERVER[$name] = $saved['server'];
            $_ENV[$name] = $saved['env'];
            if (null === $saved['server']) {
                unset($_SERVER[$name]);
            }
            if (null === $saved['env']) {
                unset($_ENV[$name]);
            }
        }
        parent::tearDown();
    }

    public function testBothTabsAreShownWithTheMastodonTabOpenByDefault(): void
    {
        $this->client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.auth-tabs [role="tab"]');
        self::assertSelectorTextSame('#auth-tab-mastodon', 'Mastodon');
        self::assertSelectorTextSame('#auth-tab-socialize', 'socialize.example');
        self::assertSelectorExists('#auth-tab-mastodon[aria-selected="true"]');
        self::assertSelectorExists('#auth-panel-socialize[hidden]');
        self::assertSelectorNotExists('#auth-panel-mastodon[hidden]');

        // Mastodon : serveur, identifiant, création de compte (MASTODON_SIGNUP=remote.test) et mot de passe oublié.
        self::assertSelectorExists('#auth-panel-mastodon input[name="username"]');
        self::assertSelectorExists('#auth-panel-mastodon a[href="/inscription/mastodon"]');
        self::assertSelectorExists('#auth-panel-mastodon a[href="https://remote.test/auth/password/new"][target="_blank"]');

        // socialize.example : identifiant, mot de passe et mot de passe oublié.
        self::assertSelectorExists('#auth-panel-socialize input[name="identifier"]');
        self::assertSelectorExists('#auth-panel-socialize input[name="password"]');
        self::assertSelectorExists('#auth-panel-socialize a[href="/mot-de-passe-oublie"]');
    }

    public function testTheTabCanBeChosenAndALoginErrorOpensTheSocializeTab(): void
    {
        $this->client->request('GET', '/connexion?onglet=socialize');
        self::assertSelectorExists('#auth-tab-socialize[aria-selected="true"]');
        self::assertSelectorExists('#auth-panel-mastodon[hidden]');
        self::assertSelectorExists('[data-auth-tabs-forced-value="socialize"]', 'Onglet imposé : le dernier choisi ne le remplace pas.');

        $this->register('camille', password: 'mot-de-passe-solide');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifier' => 'camille', 'password' => 'mauvais-mot-de-passe']));
        $this->client->followRedirect();
        self::assertSelectorExists('#auth-tab-socialize[aria-selected="true"]');
        self::assertSelectorExists('#auth-panel-socialize .form-errors');
    }

    public function testWithoutMastodonOnlyTheSocializeFormIsShown(): void
    {
        $this->setEnv('MASTODON_LOGIN', 'closed');
        $this->client->request('GET', '/connexion');
        self::assertSelectorNotExists('.auth-tabs');
        self::assertSelectorNotExists('input[name="username"]');
        self::assertSelectorExists('input[name="identifier"]');
        self::assertSelectorExists('a[href="/mot-de-passe-oublie"]');
    }

    public function testSeveralServersAreOfferedInAList(): void
    {
        $this->setEnv('MASTODON_LOGIN', 'remote.test,mastodon.social');
        $this->client->request('GET', '/connexion');
        self::assertSelectorTextSame('select[name="server"] option:first-child', 'mastodon.social', 'mastodon.social en tête.');
        self::assertSelectorExists('select[name="server"] option[value="remote.test"]');
        // Serveur présélectionné autre que celui de MASTODON_SIGNUP : création de compte sur le serveur lui-même.
        self::assertSelectorExists('#auth-panel-mastodon a[href="https://mastodon.social/auth/sign_up"]');
        self::assertSelectorExists('#auth-panel-mastodon a[href="https://mastodon.social/auth/password/new"]');
    }

    public function testAnyServerCanBeTypedWhenMastodonIsOpen(): void
    {
        $this->setEnv('MASTODON_LOGIN', 'open');
        $this->client->request('GET', '/connexion');
        self::assertSelectorExists('input[type="text"][name="server"][value="mastodon.social"]');
    }

    private function setEnv(string $name, string $value): void
    {
        $this->savedEnv[$name] ??= ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null];
        $_SERVER[$name] = $_ENV[$name] = $value;
        self::ensureKernelShutdown();
        $this->client = self::createClient(server: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
