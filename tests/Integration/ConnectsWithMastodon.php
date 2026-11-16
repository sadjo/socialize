<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Integration\Mastodon\Domain\MastodonUser;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;

/**
 * Connexion de test avec le compte Mastodon d'alice (faux serveur remote.test).
 *
 * @phpstan-require-extends \App\Tests\AppWebTestCase
 */
trait ConnectsWithMastodon
{
    private function connect(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter avec remote.test')->form());
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->client->request('GET', '/connexion/mastodon/retour?'.http_build_query(['code' => FakeMastodon::CODE, 'state' => $query['state'] ?? '']));
        self::assertResponseRedirects('/');
    }

    private function mastodonUser(): MastodonUser
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $user = self::getContainer()->get(MastodonUserRepository::class)->findOneBy([]);
        self::assertInstanceOf(MastodonUser::class, $user);

        return $user;
    }
}
