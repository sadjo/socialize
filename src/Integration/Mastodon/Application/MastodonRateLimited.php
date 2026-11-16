<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Le serveur Mastodon refuse pour l'instant (débit atteint, erreur 429) : réessayer après le délai indiqué.
 */
final class MastodonRateLimited extends MastodonException
{
    /**
     * @param positive-int $retryAfter secondes à attendre
     */
    public function __construct(
        string $message,
        public readonly int $retryAfter,
    ) {
        parent::__construct($message);
    }
}
