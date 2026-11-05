<?php

declare(strict_types=1);

namespace App\Federation\Application;

/**
 * Message Messenger : récupérer les publications récentes d'un compte distant qu'on vient de suivre.
 */
final readonly class BackfillAccount
{
    public function __construct(
        public string $accountId,
    ) {
    }
}
