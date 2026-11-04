<?php

declare(strict_types=1);

namespace App\Federation\Application;

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
     * Texte brut → HTML envoyé : échappé, paragraphes, hashtags et mentions en liens.
     *
     * @param callable(string): string $hashtagUrl
     * @param callable(string): string $mentionUrl
     */
    public static function toHtml(string $text, callable $hashtagUrl, callable $mentionUrl): string
    {
        $paragraphs = preg_split("/\n{2,}/", trim($text)) ?: [];
        $html = [];
        foreach ($paragraphs as $paragraph) {
            $escaped = htmlspecialchars($paragraph, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $escaped = (string) preg_replace_callback('/(?<![\w&])#([\p{L}\p{N}_]{1,100})/u', static fn (array $m): string => \sprintf('<a href="%s" class="mention hashtag" rel="tag">#<span>%s</span></a>', htmlspecialchars($hashtagUrl($m[1])), $m[1]), $escaped);
            $escaped = (string) preg_replace_callback('/(?<![\w\/])@([a-z0-9_.]{1,30}(?:@[a-z0-9.-]+\.[a-z]{2,})?)/iu', static fn (array $m): string => \sprintf('<span class="h-card"><a href="%s" class="u-url mention">@<span>%s</span></a></span>', htmlspecialchars($mentionUrl($m[1])), explode('@', $m[1])[0]), $escaped);
            $html[] = '<p>'.nl2br($escaped, false).'</p>';
        }

        return implode('', $html);
    }

    /**
     * @return list<string> hashtags (sans #), dans l'ordre d'apparition, sans doublon
     */
    public static function hashtags(string $text): array
    {
        preg_match_all('/(?<![\w&])#([\p{L}\p{N}_]{1,100})/u', $text, $m);

        return array_values(array_unique($m[1]));
    }
}
