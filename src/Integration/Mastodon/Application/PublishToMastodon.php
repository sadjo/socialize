<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Envoi d'une publication sur un compte Mastodon, en tâche de fond : médias (dans l'ordre), puis le statut.
 */
final readonly class PublishToMastodon
{
    /**
     * @param list<array{path: string, mimeType: string, alt: ?string}> $media      fichiers à envoyer, dans l'ordre
     * @param 'public'|'unlisted'|'private'                             $visibility
     */
    public function __construct(
        public string $publicationId,
        public array $media,
        public string $text,
        public string $visibility,
        public ?string $spoiler,
    ) {
    }
}
