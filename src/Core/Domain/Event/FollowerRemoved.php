<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Account\Account;

/**
 * Un compte local a retiré l'un de ses abonnés (l'abonnement est déjà supprimé).
 */
final readonly class FollowerRemoved
{
    public function __construct(
        public Account $account,
        public Account $follower,
        public ?string $followApUri,
    ) {
    }
}
