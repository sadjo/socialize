<?php

declare(strict_types=1);

namespace App\Federation\Application;

/**
 * Lire la page du lien d'une publication texte, pour son aperçu (FetchLinkPreviewHandler).
 */
final readonly class FetchLinkPreview
{
    public function __construct(
        public string $postId,
    ) {
    }
}
