<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

use App\Core\Domain\Account\Account;

/**
 * Une page d'une liste d'abonnés ou d'abonnements, des plus récents aux plus anciens.
 */
final readonly class FollowPage
{
    /**
     * @param list<Account> $accounts
     * @param string|null   $next     position de la page suivante (identifiant de l'abonnement)
     */
    public function __construct(
        public array $accounts,
        public ?string $next,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->accounts;
    }
}
