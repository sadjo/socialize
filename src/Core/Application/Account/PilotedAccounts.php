<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

use App\Core\Domain\Account\Account;

/**
 * Comptes d'autres serveurs pilotés depuis cette instance (connexion avec un compte Mastodon) : leurs publications
 * sont gardées ici, même sans abonné local, pour leur profil.
 */
interface PilotedAccounts
{
    public function isPiloted(Account $account): bool;
}
