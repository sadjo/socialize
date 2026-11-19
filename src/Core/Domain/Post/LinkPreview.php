<?php

declare(strict_types=1);

namespace App\Core\Domain\Post;

/**
 * Aperçu du premier lien d'une publication texte (carte du fil) : site, titre, description et image, lus sur la page
 * (balises Open Graph). L'image est une copie locale (chemin dans les copies publiques), jamais l'adresse d'origine.
 */
final readonly class LinkPreview
{
    public function __construct(
        public string $url,
        public ?string $site,
        public ?string $title,
        public ?string $description,
        public ?string $image,
    ) {
    }

    /** Nom affiché du site : celui que donne la page, sinon le domaine du lien (sans « www. »). */
    public function siteName(): string
    {
        $host = (string) parse_url($this->url, \PHP_URL_HOST);

        return $this->site ?? (str_starts_with($host, 'www.') ? substr($host, 4) : $host);
    }
}
