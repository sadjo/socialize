<?php

declare(strict_types=1);

namespace App\Core\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Identifiants courts des URL : UUID encodé en base58 (22 caractères).
 */
final class PublicId
{
    public static function decode(string $value): ?Uuid
    {
        if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{22}$/', $value)) {
            return null;
        }

        try {
            return Uuid::fromBase58($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
