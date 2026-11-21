<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Story\Highlight;
use App\Media\Infrastructure\VipsImageProcessor;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Image de couverture choisie d'une story à la une : une photo recadrée en carré, rangée avec les copies. La retirer
 * fait revenir la couverture par défaut (la story la plus récente).
 */
final readonly class HighlightCovers
{
    public const int SIZE = 400;
    public const string DIRECTORY = 'highlights';

    public function __construct(
        private FilesystemOperator $derivativesStorage,
        private VipsImageProcessor $images,
    ) {
    }

    public function replace(Highlight $highlight, File $file): void
    {
        if (!str_starts_with((string) $file->getMimeType(), 'image/')) {
            throw new MediaProcessingException('Choisissez une photo pour la couverture (pas une vidéo).');
        }
        $framed = $this->images->framed($this->images->profileSource((string) file_get_contents($file->getPathname())), self::SIZE, self::SIZE);
        $path = \sprintf('%s/%s-%s.webp', self::DIRECTORY, $highlight->getPublicId(), bin2hex(random_bytes(4)));
        $this->derivativesStorage->write($path, $framed);
        $this->delete($highlight->getCoverPath());
        $highlight->changeCover($path);
    }

    public function remove(Highlight $highlight): void
    {
        $this->delete($highlight->getCoverPath());
        $highlight->changeCover(null);
    }

    public function delete(?string $path): void
    {
        if (null === $path) {
            return;
        }
        try {
            $this->derivativesStorage->delete($path);
        } catch (FilesystemException) {
            // Fichier déjà absent.
        }
    }
}
