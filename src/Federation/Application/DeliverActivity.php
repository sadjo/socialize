<?php

declare(strict_types=1);

namespace App\Federation\Application;

/**
 * Message Messenger : livrer une activité signée par un compte local dans une boîte de réception.
 */
final readonly class DeliverActivity
{
    public function __construct(
        public string $inbox,
        public string $json,
        public string $signerAccountId,
    ) {
    }
}
