<?php

declare(strict_types=1);

namespace App\Core\Application\Post;

/**
 * Adresses web d'un texte (légende, commentaire, bio), à afficher en liens : avec « http(s):// », ou sans
 * (« www.exemple.fr », « exemple.fr/agenda »). Sans « http(s):// », un nom de domaine n'est reconnu que s'il commence
 * par « www. » ou finit par une extension courante (TLDS) : « photo.jpg » ou « v0.5.1 » restent du texte, de même
 * qu'un domaine collé à « @ » (adresse e-mail, compte du fédivers) ou à « # ».
 *
 * La ponctuation qui suit une adresse (« Voir https://exemple.fr. ») n'en fait pas partie, ni une parenthèse fermante
 * sans parenthèse ouvrante dans l'adresse (« (voir https://exemple.fr) »).
 */
final class Links
{
    private const string TLDS = 'com|net|org|info|biz|edu|gov|io|co|me|tv|fm|ai|app|dev|page|blog|site|online|store|shop|xyz|art|photo|photos|photography|gallery|pics|video|social|network|news|media|studio|design|travel|eu|fr|be|ch|lu|mc|ca|quebec|us|uk|de|at|es|it|pt|nl|dk|se|no|fi|pl|cz|ie|gr|ro|hu|jp|kr|cn|in|au|nz|br|mx|ar|ma|tn|dz|sn|ci|cm|re|gp|mq|nc|pf|bzh|paris|alsace|corsica';

    private const string LABEL = '[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?';

    private const string PATTERN = '~(?<![\w/@#.\-])(?:https?://[^\s<>"]+|(?:www\.'.self::LABEL.'(?:\.'.self::LABEL.')+|(?:'.self::LABEL.'\.)+(?:'.self::TLDS.'))(?![\p{L}\p{N}_-])(?!\.[\p{L}\p{N}])(?::\d{1,5})?(?:[/?#][^\s<>"]*)?)~iu';

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
     * Adresse complète d'un lien : « https:// » ajouté quand il n'a pas été saisi.
     */
    public static function href(string $url): string
    {
        return 1 === preg_match('~^https?://~i', $url) ? $url : 'https://'.$url;
    }

    /**
     * Texte sans ses adresses (remplacées par une espace), pour y chercher hashtags et mentions.
     */
    public static function without(string $text): string
    {
        return implode('', array_map(static fn (array $part): string => $part[0] ? ' ' : $part[1], self::split($text)));
    }

    /**
     * Adresse telle qu'affichée : sans « https:// » ni « www. » (saisis ou non), raccourcie si elle est longue.
     *
     * @return array{0: string, 1: string, 2: string} préfixe masqué, partie visible, reste masqué (raccourcie)
     */
    public static function display(string $url): array
    {
        preg_match('~^((?:https?://)?(?:www\.)?)(.*)$~is', $url, $m);
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
        $host = parse_url(self::href($url), \PHP_URL_HOST);

        return \is_string($host) && 1 === preg_match('/^[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*$/u', $host);
    }
}
