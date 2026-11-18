<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Identifiants pour une liste « IN (:ids) » : Doctrine ne sait pas convertir lui-même un tableau d'UUID. Ils sont
 * convertis comme le fait le type uuid (binaire, ou texte sur une base à type UUID natif), à lier avec
 * ArrayParameterType::STRING, comme un paramètre UuidType::NAME seul.
 */
final class UuidList
{
    /**
     * @param list<Uuid|string> $ids UUID, ou valeurs brutes lues en base (IDENTITY…)
     *
     * @return list<string>
     */
    public static function of(array $ids, AbstractPlatform $platform): array
    {
        $type = Type::getType(UuidType::NAME);

        return array_map(static function (Uuid|string $id) use ($type, $platform): string {
            $value = $type->convertToDatabaseValue($id, $platform);
            \assert(\is_string($value));

            return $value;
        }, $ids);
    }
}
