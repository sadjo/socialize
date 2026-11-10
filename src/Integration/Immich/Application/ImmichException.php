<?php

declare(strict_types=1);

namespace App\Integration\Immich\Application;

/**
 * Erreur d'échange avec Immich ; le message est montré tel quel.
 */
final class ImmichException extends \RuntimeException
{
    public function __construct(
        string $message,
        /** Erreur durable (clé refusée, droits manquants) : inutile de réessayer. */
        public readonly bool $permanent = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
