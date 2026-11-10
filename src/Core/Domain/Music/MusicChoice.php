<?php

declare(strict_types=1);

namespace App\Core\Domain\Music;

/**
 * Musique choisie pour une publication ou une story : un morceau et le début de l'extrait joué.
 */
final readonly class MusicChoice
{
    /** Durée de l'extrait joué en boucle, en secondes. */
    public const int EXCERPT = 30;

    public int $start;

    public function __construct(
        public MusicTrack $track,
        int $start = 0,
    ) {
        $this->start = $track->clampStart($start);
    }
}
