<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

/**
 * Suppression d'un compte de l'instance, en tâche de fond : fichiers, fédivers, puis données.
 */
final readonly class DeleteAccount
{
    public function __construct(
        public string $accountId,
    ) {
    }
}
