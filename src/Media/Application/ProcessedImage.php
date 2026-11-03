<?php

declare(strict_types=1);

namespace App\Media\Application;

final readonly class ProcessedImage
{
    /**
     * @param array<string, string> $files contenus WebP par nom de dérivé
     * @param array<string, string> $exif
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $files,
        public array $exif,
        public ?string $blurhash,
    ) {
    }
}
