<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use App\Tests\AppWebTestCase;
use App\Tests\Federation\FakeFediverse;

/**
 * Création d'un compte Mastodon depuis l'écran de connexion (MASTODON_SIGNUP=remote.test dans .env.test), puis
 * connexion avec ce compte une fois son adresse confirmée.
 */
final class MastodonSignupTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
        FakeFediverse::$outbox = [];
    }

    public function testAnAccountIsCreatedThenConnectedOnceItsAddressIsConfirmed(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->click($crawler->selectLink('Créer un compte sur remote.test')->link());
        self::assertSelectorTextContains('h1', 'Créer un compte sur remote.test');
        self::assertSelectorTextContains('.mastodon-rules', 'Respectez les autres.');
        self::assertSelectorExists('input[name="mastodon_signup[dateOfBirth]"]', 'Âge minimal demandé par le serveur.');

        // Refus du serveur : sous les champs concernés.
        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Créer mon compte')->form(), $this->fields('pris'));
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('form[name="mastodon_signup"] li', 'est déjà utilisé');
        self::assertAnySelectorTextContains('form[name="mastodon_signup"] li', 'est trop court');

        $crawler = $this->client->request('GET', '/inscription/mastodon');
        $this->client->submit($crawler->selectButton('Créer mon compte')->form(), $this->fields('alice'));
        self::assertResponseRedirects('/inscription/mastodon/confirmation');
        self::assertSame([['username' => 'alice', 'email' => 'alice@exemple.test', 'locale' => 'fr', 'date_of_birth' => '2000-05-17', 'agreement' => 'true']], FakeMastodon::$signups, 'Le mot de passe ne fait que passer.');
        self::assertCount(2, array_filter(FakeMastodon::requestsTo('POST', '/oauth/token'), static fn (array $r): bool => 'client_credentials' === ($r['body']['grant_type'] ?? null)), 'Un jeton d’application par inscription.');

        // Adresse pas encore confirmée : le serveur refuse le jeton.
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.card', '@alice@remote.test');
        $this->client->submit($crawler->selectButton('J’ai confirmé mon adresse')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors', 'n’est pas encore confirmée');
        self::assertSame([], self::getContainer()->get(MastodonUserRepository::class)->findAll());

        FakeMastodon::$confirmed = true;
        $this->client->submit($this->client->getCrawler()->selectButton('J’ai confirmé mon adresse')->form());
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'Votre compte @alice@remote.test est prêt');
        self::assertSelectorExists('.side a[href="/@alice@remote.test"]');
        self::assertCount(1, self::getContainer()->get(MastodonUserRepository::class)->findAll());
    }

    public function testClosedRegistrationsLeadToTheServerSignUpPage(): void
    {
        FakeMastodon::$registrationsOpen = false;
        $this->client->request('GET', '/inscription/mastodon');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="https://remote.test/auth/sign_up"]');
        self::assertSelectorNotExists('form[name="mastodon_signup"]');
    }

    /**
     * @return array<string, string>
     */
    private function fields(string $username): array
    {
        return [
            'mastodon_signup[username]' => $username,
            'mastodon_signup[email]' => $username.'@exemple.test',
            'mastodon_signup[password]' => 'mot-de-passe-solide',
            'mastodon_signup[dateOfBirth]' => '2000-05-17',
            'mastodon_signup[agreement]' => '1',
        ];
    }
}
