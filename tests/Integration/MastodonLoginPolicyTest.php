<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Integration\Mastodon\Application\MastodonLoginPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Ce que propose l'écran de connexion selon MASTODON_LOGIN : mastodon.social d'un clic, et un champ pour les autres.
 */
final class MastodonLoginPolicyTest extends TestCase
{
    public function testMastodonSocialIsFeaturedAndOtherServersRemainPossible(): void
    {
        self::assertNull(new MastodonLoginPolicy('closed')->choices());
        self::assertNull(new MastodonLoginPolicy('')->choices());

        // Ouverte : bouton mastodon.social, et un champ libre pour tout autre serveur.
        self::assertSame(['buttons' => ['mastodon.social'], 'field' => []], new MastodonLoginPolicy('open')->choices());

        // Quelques serveurs : un bouton chacun, mastodon.social en premier.
        self::assertSame(['buttons' => ['mastodon.social', 'piaille.fr'], 'field' => null], new MastodonLoginPolicy('piaille.fr, mastodon.social')->choices());
        self::assertSame(['buttons' => ['piaille.fr'], 'field' => null], new MastodonLoginPolicy('piaille.fr')->choices());

        // Beaucoup de serveurs : bouton mastodon.social, champ avec les autres en suggestion.
        self::assertSame(
            ['buttons' => ['mastodon.social'], 'field' => ['piaille.fr', 'mamot.fr', 'framapiaf.org']],
            new MastodonLoginPolicy('piaille.fr,mastodon.social,mamot.fr,framapiaf.org')->choices(),
        );
        self::assertSame(
            ['buttons' => [], 'field' => ['piaille.fr', 'mamot.fr', 'framapiaf.org', 'eldritch.cafe']],
            new MastodonLoginPolicy('piaille.fr,mamot.fr,framapiaf.org,eldritch.cafe')->choices(),
        );
    }
}
