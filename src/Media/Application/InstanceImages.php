<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Infrastructure\VipsImageProcessor;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Images insérées par l'administrateur dans le bloc Instance, converties en WebP et publiées avec les autres copies.
 */
final readonly class InstanceImages
{
    public function __construct(
        private FilesystemOperator $derivativesStorage,
        private VipsImageProcessor $images,
        private MediaUrls $urls,
    ) {
    }

    /**
     * @return string adresse publique de l'image
     */
    public function store(File $file): string
    {
        $contents = $this->images->contentImage((string) file_get_contents($file->getPathname()));
        $path = \sprintf('instance/%s.webp', bin2hex(random_bytes(8)));
        $this->derivativesStorage->write($path, $contents);

        return $this->urls->path($path);
    }
}
