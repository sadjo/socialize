<?php

declare(strict_types=1);

namespace App\Integration\Immich\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaType;
use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\ImmichClient;
use App\Media\Application\Library\LibraryMedia;
use App\Media\Application\Library\LibrarySource;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\MediaRules;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Photothèque Immich comme source de médias à publier (jetons « i-<id> »).
 * L'EXIF connu d'Immich (date, lieu, objectif, GPS) complète celui de l'original ; les médias publiés rejoignent
 * l'album « Publié sur Socialize » si le compte l'a demandé. D'une photo live, « i-<id> » publie la photo fixe,
 * « i-<id>-live » sa courte vidéo (avec l'EXIF de la photo, qui seule rejoint l'album).
 */
final readonly class ImmichLibrarySource implements LibrarySource
{
    public function __construct(
        private ImmichConnections $connections,
        private ImmichClient $client,
        private MessageBusInterface $bus,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function prefix(): string
    {
        return 'i';
    }

    public function fetch(Account $author, string $reference): LibraryMedia
    {
        if (!preg_match('/^([0-9a-f-]{36})('.preg_quote(LibrarySource::LIVE, '/').')?$/i', $reference, $m)) {
            throw new MediaProcessingException('La sélection de médias est invalide. Rechargez la page et réessayez.');
        }
        $connection = $this->connections->of($author) ?? throw new MediaProcessingException('Connectez Immich dans les réglages pour publier depuis votre photothèque.');
        [$server, $key] = $this->connections->credentials($connection);

        $path = null;
        try {
            $asset = $this->client->asset($server, $key, $m[1]);
            $file = isset($m[2]) ? $this->client->asset($server, $key, $asset->liveVideoId ?? throw new MediaProcessingException(\sprintf('« %s » n’est pas une photo live.', $asset->fileName))) : $asset;
            $this->check($file);
            $path = $this->filesystem->tempnam(sys_get_temp_dir(), 'socialize-immich-', '.'.(pathinfo($file->fileName, \PATHINFO_EXTENSION) ?: ($file->video ? 'mov' : 'jpg')));
            $this->client->download($server, $key, $file->id, $path);

            return new LibraryMedia($asset->id, new File($path), $asset->exif);
        } catch (ImmichException $e) {
            $this->filesystem->remove($path ?? []);
            throw new MediaProcessingException($e->getMessage(), $e->getCode(), previous: $e);
        } catch (MediaProcessingException $e) {
            $this->filesystem->remove($path ?? []);
            throw $e;
        }
    }

    public function published(Account $author, array $references): void
    {
        $connection = $this->connections->of($author);
        if ($connection instanceof ImmichConnection && $connection->addsToAlbum() && [] !== $references) {
            $this->bus->dispatch(new AddToImmichAlbum($connection->getId()->toRfc4122(), $references));
        }
    }

    private function check(ImmichAsset $asset): void
    {
        $limit = $asset->video ? MediaRules::MAX_VIDEO_SIZE : MediaRules::MAX_IMAGE_SIZE;
        if (null !== $asset->size && $asset->size > (int) $limit * 1024 * 1024) {
            throw new MediaProcessingException(\sprintf('« %s » dépasse la taille maximale (%so).', $asset->fileName, $limit));
        }
        if (null !== $asset->mimeType && !MediaRules::typeOf($asset->mimeType) instanceof MediaType) {
            throw new MediaProcessingException(\sprintf('Le format de « %s » (%s) n’est pas accepté.', $asset->fileName, $asset->mimeType));
        }
    }
}
