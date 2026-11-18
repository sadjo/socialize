<?php

declare(strict_types=1);

namespace App\Core\Application\Post;

/**
 * Adresses web (http, https) d'un texte (légende, commentaire, bio), à afficher en liens.
 *
 * La ponctuation qui suit une adresse (« Voir https://exemple.fr. ») n'en fait pas partie, ni une parenthèse fermante
 * sans parenthèse ouvrante dans l'adresse (« (voir https://exemple.fr) »).
 */
final class Links
{
    private const string PATTERN = '~(?<![\w/])https?://[^\s<>"]+~iu';

    /** Longueur au-delà de laquelle l'adresse affichée est raccourcie. */
    private const int DISPLAY_MAX = 30;

    /**
     * Découpe un texte en morceaux : texte ordinaire (false) et adresses (true), dans l'ordre.
     *
     * @return list<array{0: bool, 1: string}>
     */
    public static function split(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $all, \PREG_OFFSET_CAPTURE);
        $parts = [];
        $offset = 0;
        foreach ($all[0] as [$url, $start]) {
            $url = self::trim($url);
            if (!self::valid($url)) {
                continue;
            }
            if ($start > $offset) {
                $parts[] = [false, substr($text, $offset, $start - $offset)];
            }
            $parts[] = [true, $url];
            $offset = $start + \strlen($url);
        }
        if ($offset < \strlen($text)) {
            $parts[] = [false, substr($text, $offset)];
        }

        return $parts;
    }

    /**
     * Applique une transformation au texte hors des adresses, qui restent telles quelles (un « #ancre » ou un « @nom »
     * dans une adresse n'est ni un hashtag ni une mention).
     *
     * @param callable(string): string $transform
     */
    public static function outside(string $text, callable $transform): string
    {
        return implode('', array_map(static fn (array $part): string => $part[0] ? $part[1] : $transform($part[1]), self::split($text)));
    }

    /**
     * Texte sans ses adresses (remplacées par une espace), pour y chercher hashtags et mentions.
     */
    public static function without(string $text): string
    {
        return implode('', array_map(static fn (array $part): string => $part[0] ? ' ' : $part[1], self::split($text)));
    }

    /**
     * Adresse telle qu'affichée : sans « https:// » ni « www. », raccourcie si elle est longue.
     *
     * @return array{0: string, 1: string, 2: string} préfixe masqué, partie visible, reste masqué (raccourcie)
     */
    public static function display(string $url): array
    {
        preg_match('~^(https?://(?:www\.)?)(.*)$~is', $url, $m);
        $prefix = $m[1] ?? '';
        $rest = $m[2] ?? $url;
        if (mb_strlen($rest) <= self::DISPLAY_MAX) {
            return [$prefix, $rest, ''];
        }

        return [$prefix, mb_substr($rest, 0, self::DISPLAY_MAX), mb_substr($rest, self::DISPLAY_MAX)];
    }

    private static function trim(string $url): string
    {
        while ('' !== $url) {
            $last = substr($url, -1);
            if (str_contains('.,;:!?\'*', $last)
                || (')' === $last && substr_count($url, '(') < substr_count($url, ')'))
                || (']' === $last && substr_count($url, '[') < substr_count($url, ']'))) {
                $url = substr($url, 0, -1);
                continue;
            }
            break;
        }

        return $url;
    }

    private static function valid(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && 1 === preg_match('/^[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*$/u', $host);
    }
}
