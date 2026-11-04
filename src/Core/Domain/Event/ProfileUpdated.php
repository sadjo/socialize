<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Account\Account;

/**
 * Un compte local a modifié son profil public.
 */
final readonly class ProfileUpdated
{
    public function __construct(
        public Account $account,
    ) {
    }
}
