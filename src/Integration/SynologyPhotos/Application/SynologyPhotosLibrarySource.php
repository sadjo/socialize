<?php

declare(strict_types=1);

namespace App\Integration\SynologyPhotos\Application;

use App\Core\Domain\Account\Account;
use App\Integration\SynologyPhotos\Infrastructure\SynologyPhotosClient;
use App\Media\Application\Library\LibraryMedia;
use App\Media\Application\Library\LibrarySource;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\MediaRules;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Synology Photos comme source de médias à publier (jetons « s-p-<id> » pour l'espace personnel, « s-t-<id> »
 * pour l'espace partagé). Les métadonnées connues du NAS (appareil, objectif, GPS) complètent celles de l'original.
 */
final readonly class SynologyPhotosLibrarySource implements LibrarySource
{
    public function __construct(
        private SynologyPhotosConnections $connections,
        private SynologyPhotosClient $client,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function prefix(): string
    {
        return 's';
    }

    public function fetch(Account $author, string $reference): LibraryMedia
    {
        // Photo live : « <espace>-<id>-live » publie sa courte vidéo, sans suffixe la photo fixe.
        if (!preg_match('/^([pt])-(\d{1,18})('.preg_quote(LibrarySource::LIVE, '/').')?$/', $reference, $m)) {
            throw new MediaProcessingException('La sélection de médias est invalide. Rechargez la page et réessayez.');
        }
        /** @var SynologyPhotosItem::PERSONAL|SynologyPhotosItem::TEAM $space */
        $space = $m[1];
        $connection = $this->connections->of($author) ?? throw new MediaProcessingException('Connectez Synology Photos dans les réglages pour publier depuis votre photothèque.');

        try {
            [$server, $session] = $this->connections->session($connection);
            $item = $this->client->item($server, $session, $space, (int) $m[2]);
            $live = isset($m[3]);
            if ($live && !$item->live) {
                throw new MediaProcessingException(\sprintf('« %s » n’est pas une photo live.', $item->fileName));
            }
            $limit = $item->video || $live ? MediaRules::MAX_VIDEO_SIZE : MediaRules::MAX_IMAGE_SIZE;
            if (null !== $item->size && $item->size > (int) $limit * 1024 * 1024) {
                throw new MediaProcessingException(\sprintf('« %s » dépasse la taille maximale (%so).', $item->fileName, $limit));
            }
            $extension = $live ? 'mov' : (strtolower(pathinfo($item->fileName, \PATHINFO_EXTENSION)) ?: ($item->video ? 'mp4' : 'jpg'));
            $path = $this->filesystem->tempnam(sys_get_temp_dir(), 'socialize-synology-', '.'.$extension);
            try {
                if ($live) {
                    $this->client->liveVideo($server, $session, $space, $item->id, $path, (int) $limit * 1024 * 1024);
                } else {
                    $this->client->download($server, $session, $space, $item->id, $path, $item->fileName, $item->video, (int) $limit * 1024 * 1024);
                }
            } catch (SynologyPhotosException $e) {
                $this->filesystem->remove($path);
                throw $e;
            }

            return new LibraryMedia($item->reference(), new File($path), $item->exif);
        } catch (SynologyPhotosException $e) {
            $this->connections->noteFailure($connection, $e);
            throw new MediaProcessingException($e->getMessage(), $e->getCode(), previous: $e);
        }
    }

    public function published(Account $author, array $references): void
    {
        // Rien à faire sur le NAS après la publication.
    }
}
