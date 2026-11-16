<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

/**
 * Le serveur d'un compte piloté d'ici limite son débit : l'envoi reprend après le délai indiqué.
 */
final class PilotedRateLimited extends \DomainException
{
    /**
     * @param positive-int $retryAfter secondes à attendre
     */
    public function __construct(
        string $message,
        public readonly int $retryAfter,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
