<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Account\Account;

/**
 * Une personne a rejoint l'instance d'elle-même : inscription (ouverte ou sur invitation), ou première connexion avec
 * son compte Mastodon. Pas pour un compte créé par l'administrateur.
 */
final readonly class AccountRegistered
{
    public function __construct(
        public Account $account,
        public bool $viaMastodon = false,
    ) {
    }
}
