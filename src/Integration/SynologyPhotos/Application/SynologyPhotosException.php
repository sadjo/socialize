<?php

declare(strict_types=1);

namespace App\Integration\SynologyPhotos\Application;

/**
 * Échec d'un échange avec Synology Photos. $sessionExpired : la session DSM n'est plus valable, le compte doit se
 * reconnecter dans ses réglages.
 */
final class SynologyPhotosException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $sessionExpired = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
