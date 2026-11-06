<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\FollowRepository;

/**
 * Qui peut voir les abonnés et les abonnements d'un compte.
 */
final readonly class NetworkAccess
{
    public function __construct(
        private FollowRepository $follows,
    ) {
    }

    public function canView(Account $account, ?Account $viewer): bool
    {
        if (!$account->isNetworkHidden()) {
            return true;
        }
        if ($viewer instanceof Account && $viewer->getId()->equals($account->getId())) {
            return true;
        }

        return $this->follows->isFollowing($viewer, $account);
    }
}
