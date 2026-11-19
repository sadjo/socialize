<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Application\Post\Links;

/**
 * Aperçu d'un lien : premier lien d'un texte, et ce qu'une page dit d'elle-même (balises Open Graph, à défaut le titre
 * et la description de la page).
 */
final class LinkPreviewParser
{
    /**
     * Premier lien web d'un texte (publication locale, ou reçue : le texte d'une Note garde l'adresse entière).
     */
    public static function firstLink(string $text): ?string
    {
        foreach (Links::split($text) as [$isLink, $part]) {
            if ($isLink) {
                $href = Links::href($part);

                return 1 === preg_match('#^https?://#i', $href) ? $href : null;
            }
        }

        return null;
    }

    /**
     * @return array{site: ?string, title: ?string, description: ?string, image: ?string} image en adresse absolue
     */
    public static function parse(string $html, string $pageUrl): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $meta = [];
        foreach ($doc->getElementsByTagName('meta') as $tag) {
            $key = mb_strtolower(trim($tag->getAttribute('property') ?: $tag->getAttribute('name')));
            $content = self::clean($tag->getAttribute('content'));
            if ('' !== $key && null !== $content && !isset($meta[$key])) {
                $meta[$key] = $content;
            }
        }
        $title = $doc->getElementsByTagName('title')->item(0);

        return [
            'site' => $meta['og:site_name'] ?? null,
            'title' => $meta['og:title'] ?? $meta['twitter:title'] ?? self::clean($title?->textContent),
            'description' => $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null,
            'image' => self::absolute($meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? null, $pageUrl),
        ];
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return '' === $value ? null : $value;
    }

    /**
     * Adresse d'image résolue par rapport à la page ; seulement en http(s).
     */
    private static function absolute(?string $url, string $pageUrl): ?string
    {
        if (null === $url) {
            return null;
        }
        $page = parse_url($pageUrl);
        $origin = ($page['scheme'] ?? 'https').'://'.($page['host'] ?? '').(isset($page['port']) ? ':'.$page['port'] : '');
        $url = match (true) {
            str_starts_with($url, '//') => ($page['scheme'] ?? 'https').':'.$url,
            str_starts_with($url, '/') => $origin.$url,
            1 === preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) => $url,
            default => $origin.rtrim(\dirname($page['path'] ?? '/').'/', '/').'/'.$url,
        };

        return 1 === preg_match('#^https?://#i', $url) ? $url : null;
    }
}
