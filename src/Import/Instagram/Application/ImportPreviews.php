<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

use App\Import\Instagram\Domain\ImportItem;
use App\Import\Instagram\Infrastructure\ArchiveReader;
use Jcupitt\Vips\Image;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Vignettes des publications avant leur import, tirées de l'archive et gardées dans son dossier.
 */
final readonly class ImportPreviews
{
    private const int SIZE = 240;

    public function __construct(
        private ArchiveUploads $uploads,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return string|null chemin de la vignette (WebP), null si l'élément n'a pas de photo
     */
    public function thumbnail(ImportItem $item): ?string
    {
        $import = $item->getImport();
        $path = \sprintf('%s/apercus/%s.webp', $this->uploads->dir($import), $item->getPublicId());
        if (is_file($path)) {
            return $path;
        }
        $photo = array_find($item->getMediaUris(), static fn (string $uri): bool => (bool) preg_match('/\.(jpe?g|png|webp|heic|gif)$/i', $uri));
        if (null === $photo) {
            return null;
        }

        $archive = new ArchiveReader(array_column($import->getParts(), 'path'));
        try {
            if (!$archive->has($photo)) {
                return null;
            }
            $thumb = Image::thumbnail_buffer($archive->read($photo), self::SIZE, ['height' => self::SIZE, 'crop' => 'centre']);
            $this->filesystem->dumpFile($path, $thumb->writeToBuffer('.webp', ['Q' => 75, 'strip' => true]));
        } catch (\Jcupitt\Vips\Exception) {
            return null;
        } finally {
            $archive->close();
        }

        return $path;
    }
}
