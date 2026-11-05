<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Account\Account;

/**
 * Un compte local ne suit plus un compte (l'abonnement est déjà supprimé).
 */
final readonly class AccountUnfollowed
{
    public function __construct(
        public Account $follower,
        public Account $following,
        public ?string $followApUri,
    ) {
    }
}
