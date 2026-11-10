<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Domain\Music\MusicTrack;
use App\Core\Infrastructure\Doctrine\MusicTrackRepository;
use App\Media\Application\MediaUrls;
use Twig\Attribute\AsTwigFunction;

/**
 * Musique des publications et des stories dans les gabarits.
 */
final readonly class MusicExtension
{
    public function __construct(
        private MediaUrls $urls,
        private MusicTrackRepository $tracks,
    ) {
    }

    #[AsTwigFunction('music_url')]
    public function url(MusicTrack $track): string
    {
        return $this->urls->path($track->getPath());
    }

    /**
     * Morceau d'un formulaire réaffiché (identifiant public), pour montrer la musique déjà choisie.
     */
    #[AsTwigFunction('music_track')]
    public function track(?string $publicId): ?MusicTrack
    {
        return null !== $publicId && '' !== $publicId ? $this->tracks->findByPublicId($publicId) : null;
    }
}
