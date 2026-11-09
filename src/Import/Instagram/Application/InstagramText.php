<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

/**
 * Textes de l'archive Instagram : le JSON y code chaque octet UTF-8 comme un caractère
 * (« Ã© » pour « é »). On recompose les octets quand c'est le cas.
 */
final class InstagramText
{
    public static function fix(string $text): string
    {
        // Seuls des caractères ≤ U+00FF : ce sont peut-être des octets UTF-8 déguisés.
        if ('' === $text || preg_match('/[^\x{00}-\x{FF}]/u', $text) || !preg_match('/[\x{80}-\x{FF}]/u', $text)) {
            return $text;
        }
        $bytes = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $text;
    }

    public static function needsFix(string $text): bool
    {
        return self::fix($text) !== $text;
    }

    /**
     * Répare toutes les chaînes d'une structure JSON décodée.
     */
    public static function fixAll(mixed $value): mixed
    {
        if (\is_string($value)) {
            return self::fix($value);
        }
        if (\is_array($value)) {
            $fixed = [];
            foreach ($value as $key => $item) {
                $fixed[\is_string($key) ? self::fix($key) : $key] = self::fixAll($item);
            }

            return $fixed;
        }

        return $value;
    }
}
