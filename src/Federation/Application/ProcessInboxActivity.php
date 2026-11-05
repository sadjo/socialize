<?php

declare(strict_types=1);

namespace App\Federation\Application;

/**
 * Message Messenger : traiter une activité reçue dont la signature a été vérifiée.
 */
final readonly class ProcessInboxActivity
{
    public function __construct(
        public string $json,
        public string $signerAccountId,
    ) {
    }
}
