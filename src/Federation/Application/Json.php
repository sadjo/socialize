<?php

declare(strict_types=1);

namespace App\Federation\Application;

/**
 * Lecture prudente des documents ActivityPub : chaque champ peut être une chaîne,
 * un objet ou une liste, et rien n'est garanti.
 */
final class Json
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Identifiant d'un champ qui peut être une URI ou un objet avec « id » (ou « href » pour un lien).
     *
     * @param array<mixed> $data
     */
    public static function id(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (\is_string($value)) {
            return $value;
        }
        if (\is_array($value)) {
            if (array_is_list($value)) {
                $value = $value[0] ?? null;

                return \is_string($value) ? $value : (\is_array($value) ? (self::string($value, 'href') ?? self::string($value, 'id')) : null);
            }

            return self::string($value, 'id') ?? self::string($value, 'href');
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value) || array_is_list($value)) {
            return null;
        }

        return self::keyed($value);
    }

    /**
     * Liste de valeurs (un élément seul devient une liste d'un élément).
     *
     * @param array<mixed> $data
     *
     * @return list<mixed>
     */
    public static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!\is_array($value)) {
            return [$value];
        }

        return array_is_list($value) ? $value : [$value];
    }

    /**
     * Adresses de destination (to + cc), sous forme de chaînes.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    public static function audience(array $data, string $key): array
    {
        $out = [];
        foreach (self::list($data, $key) as $item) {
            if (\is_string($item)) {
                $out[] = $item;
            } elseif (\is_array($item) && \is_string($item['id'] ?? null)) {
                $out[] = $item['id'];
            }
        }

        return $out;
    }

    public static function host(string $url): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? mb_strtolower($host) : null;
    }

    /**
     * Garde les clés textuelles d'un tableau JSON décodé.
     *
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function keyed(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
