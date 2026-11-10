<?php

declare(strict_types=1);

namespace App\Integration\Openverse\Application;

use App\Core\Domain\Music\MusicChoice;
use App\Core\Domain\Music\MusicTrack;
use App\Core\Infrastructure\Doctrine\MusicTrackRepository;
use App\Integration\Openverse\Infrastructure\OpenverseClient;
use App\Media\Application\MediaProcessingException;
use App\Media\Infrastructure\FfmpegAudioProcessor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Bibliothèque de musiques de l'instance : un morceau d'Openverse est téléchargé une seule fois,
 * converti en AAC et rangé dans le stockage public (music/…), puis partagé par toutes les publications.
 */
final readonly class MusicLibrary
{
    /** Durée maximale d'un morceau, en secondes. */
    public const int MAX_DURATION = 15 * 60;

    public function __construct(
        private OpenverseClient $openverse,
        private MusicTrackRepository $tracks,
        private EntityManagerInterface $em,
        private FilesystemOperator $derivativesStorage,
        private FfmpegAudioProcessor $audio,
        private LockFactory $locks,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Morceau d'Openverse, copié sur l'instance s'il ne l'est pas encore.
     *
     * @throws OpenverseException
     */
    public function import(string $openverseId): MusicTrack
    {
        $openverseId = strtolower($openverseId);
        $existing = $this->tracks->findByOpenverseId($openverseId);
        if ($existing instanceof MusicTrack) {
            return $existing;
        }

        // Deux personnes peuvent choisir le même morceau au même moment : un seul téléchargement.
        $lock = $this->locks->createLock('music-import-'.$openverseId, 300);
        $lock->acquire(true);
        $download = $this->filesystem->tempnam(sys_get_temp_dir(), 'socialize-music-');
        $converted = $download.'.m4a';
        try {
            $existing = $this->tracks->findByOpenverseId($openverseId);
            if ($existing instanceof MusicTrack) {
                return $existing;
            }

            $track = $this->openverse->track($openverseId);
            if ($track->duration > self::MAX_DURATION) {
                throw new OpenverseException('Ce morceau est trop long (15 minutes au plus).');
            }
            $this->openverse->download($track, $download);

            try {
                $duration = (int) floor($this->audio->duration($download));
                if ($duration < 1 || $duration > self::MAX_DURATION) {
                    throw new OpenverseException('Ce morceau est trop long (15 minutes au plus) ou vide.');
                }
                $this->audio->transcode($download, $converted);
            } catch (MediaProcessingException $e) {
                throw new OpenverseException($e->getMessage(), $e->getCode(), previous: $e);
            }

            $path = \sprintf('music/%s.m4a', Uuid::v7()->toBase58());
            $stream = fopen($converted, 'r') ?: throw new OpenverseException('Morceau converti illisible.');
            try {
                $this->derivativesStorage->writeStream($path, $stream);
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }

            $music = new MusicTrack(
                $track->id,
                $track->title,
                $track->artist,
                $track->license,
                $track->licenseVersion,
                $track->licenseUrl,
                $track->sourceUrl,
                $track->provider,
                $duration,
                $path,
            );
            $this->em->persist($music);
            $this->em->flush();

            return $music;
        } finally {
            $this->filesystem->remove([$download, $converted]);
            $lock->release();
        }
    }

    /**
     * Musique choisie dans un formulaire (identifiant public du morceau et début de l'extrait), ou null.
     *
     * @throws OpenverseException si le morceau n'existe pas
     */
    public function choice(?string $trackId, ?int $start): ?MusicChoice
    {
        if (null === $trackId || '' === trim($trackId)) {
            return null;
        }
        $track = $this->tracks->findByPublicId(trim($trackId)) ?? throw new OpenverseException('La musique choisie est introuvable. Choisissez-la à nouveau.');

        return new MusicChoice($track, $start ?? 0);
    }
}
