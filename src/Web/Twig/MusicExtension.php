<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Domain\Music\MusicTrack;
use App\Core\Infrastructure\Doctrine\MusicTrackRepository;
use App\Integration\Openverse\Application\OpenverseException;
use App\Integration\Openverse\Infrastructure\OpenverseClient;
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
        private OpenverseClient $openverse,
    ) {
    }

    #[AsTwigFunction('music_url')]
    public function url(MusicTrack $track): string
    {
        return $this->urls->path($track->getPath());
    }

    /**
     * Morceau déjà choisi dans un formulaire (identifiant Openverse), pour l'afficher : la copie de l'instance
     * si elle existe, sinon la fiche d'Openverse ; null si le morceau est introuvable.
     *
     * @return array{title: string, artist: string, license: string, duration: int}|null
     */
    #[AsTwigFunction('music_choice')]
    public function choice(?string $openverseId): ?array
    {
        if (null === $openverseId || '' === $openverseId) {
            return null;
        }
        $local = $this->tracks->findByOpenverseId(strtolower($openverseId));
        if ($local instanceof MusicTrack) {
            return ['title' => $local->getTitle(), 'artist' => $local->getArtist(), 'license' => $local->getLicenseLabel(), 'duration' => $local->getDuration()];
        }
        try {
            $track = $this->openverse->track($openverseId);
        } catch (OpenverseException) {
            return null;
        }

        return ['title' => $track->title, 'artist' => $track->artist, 'license' => $track->getLicenseLabel(), 'duration' => $track->duration];
    }
}
