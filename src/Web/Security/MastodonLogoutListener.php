<?php

declare(strict_types=1);

namespace App\Web\Security;

use App\Integration\Mastodon\Application\MastodonLogin;
use App\Integration\Mastodon\Domain\MastodonUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Déconnexion d'une personne connectée avec Mastodon : son jeton est révoqué sur son serveur.
 */
#[AsEventListener]
final readonly class MastodonLogoutListener
{
    public function __construct(
        private MastodonLogin $login,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof MastodonUser) {
            $this->login->logout($user);
        }
    }
}
