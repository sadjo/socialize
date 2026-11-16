<?php

declare(strict_types=1);

namespace App\Core\Application\Notification;

/**
 * Envoyer une notification sur les appareils d'un compte (en tâche de fond).
 */
final readonly class SendPush
{
    /**
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    public function __construct(
        public string $accountId,
        public array $payload,
    ) {
    }
}
