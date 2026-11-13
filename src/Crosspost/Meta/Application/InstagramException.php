<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

/**
 * Échec d'un échange avec Instagram. $temporary : à réessayer plus tard (réseau, limite de débit) ;
 * $tokenRejected : le compte doit reconnecter Instagram.
 */
final class InstagramException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $temporary = false,
        public readonly bool $tokenRejected = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
