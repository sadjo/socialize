<?php

declare(strict_types=1);

namespace App\Core\Domain\Event;

use App\Core\Domain\Account\Account;

/**
 * Un compte de l'instance va être supprimé (envoyé avant la suppression, ses données encore là) : ses fichiers
 * sont effacés, et le fédivers est prévenu.
 */
final readonly class AccountDeleting
{
    public function __construct(
        public Account $account,
    ) {
    }
}
