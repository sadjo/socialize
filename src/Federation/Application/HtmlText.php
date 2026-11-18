<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Application\Post\Links;
use App\Core\Application\Post\Mentions;

/**
 * Conversions entre le HTML du fédivers et le texte brut stocké par Socialize.
 */
final class HtmlText
{
    /**
     * HTML reçu (Mastodon, Pixelfed) → texte brut : balises retirées, paragraphes et sauts de ligne conservés.
     */
    public static function toText(?string $html): string
    {
        if (null === $html || '' === $html) {
            return '';
        }
        $text = (string) preg_replace(['#<br\s*/?>#i', '#</p>\s*<p[^>]*>#i'], ["\n", "\n\n"], $html);
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Texte brut → HTML envoyé : échappé, paragraphes, adresses web, hashtags et mentions en liens.
     *
     * @param callable(string): string $hashtagUrl
     * @param callable(string): string $mentionUrl
     */
    public static function toHtml(string $text, callable $hashtagUrl, callable $mentionUrl): string
    {
        $paragraphs = preg_split("/\n{2,}/", trim($text)) ?: [];
        $html = [];
        foreach ($paragraphs as $paragraph) {
            $escaped = '';
            foreach (Links::split($paragraph) as [$isLink, $part]) {
                $escaped .= $isLink ? self::link($part) : self::inline($part, $hashtagUrl, $mentionUrl);
            }
            $html[] = '<p>'.nl2br($escaped, false).'</p>';
        }

        return implode('', $html);
    }

    /**
     * Texte ordinaire (hors adresse web) : échappé, hashtags et mentions en liens.
     *
     * @param callable(string): string $hashtagUrl
     * @param callable(string): string $mentionUrl
     */
    private static function inline(string $text, callable $hashtagUrl, callable $mentionUrl): string
    {
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $escaped = (string) preg_replace_callback('/(?<![\w&])#([\p{L}\p{N}_]{1,100})/u', static fn (array $m): string => \sprintf('<a href="%s" class="mention hashtag" rel="tag">#<span>%s</span></a>', htmlspecialchars($hashtagUrl($m[1])), $m[1]), $escaped);

        return (string) preg_replace_callback(Mentions::PATTERN, static fn (array $m): string => null !== $m[1]
            // Compte Instagram : simple lien vers son profil (pas une mention du fédivers).
            ? \sprintf('<a href="%s" rel="nofollow noopener" target="_blank">@%s (Instagram)</a>', htmlspecialchars(Mentions::instagramUrl($m[2])), $m[2]).(null !== $m[3] ? '@'.$m[3] : '')
            : \sprintf('<span class="h-card"><a href="%s" class="u-url mention">@<span>%s</span></a></span>', htmlspecialchars($mentionUrl($m[2].(null !== $m[3] ? '@'.$m[3] : ''))), $m[2]), $escaped, flags: \PREG_UNMATCHED_AS_NULL);
    }

    /**
     * Adresse web, au format de Mastodon : « https:// » masqué et adresse longue raccourcie (classes « invisible »
     * et « ellipsis », que Mastodon et Pixelfed savent afficher).
     */
    private static function link(string $url): string
    {
        [$prefix, $visible, $rest] = Links::display($url);
        $e = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return \sprintf(
            '<a href="%s" rel="nofollow noopener noreferrer" target="_blank"><span class="invisible">%s</span><span class="%s">%s</span><span class="invisible">%s</span></a>',
            $e(Links::href($url)), $e($prefix), '' !== $rest ? 'ellipsis' : '', $e($visible), $e($rest),
        );
    }

    /**
     * @return list<string> hashtags (sans #), dans l'ordre d'apparition, sans doublon
     */
    public static function hashtags(string $text): array
    {
        preg_match_all('/(?<![\w&])#([\p{L}\p{N}_]{1,100})/u', Links::without($text), $m);

        return array_values(array_unique($m[1]));
    }
}
