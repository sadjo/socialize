<?php

declare(strict_types=1);

namespace App\Media\Application;

/**
 * Cadre choisi dans une image, en fractions de sa largeur et de sa hauteur (0 à 1) : « x,y,largeur,hauteur ».
 */
final readonly class ImageCrop
{
    private function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
    }

    /**
     * Cadre envoyé par le navigateur ou enregistré ; null s'il est absent ou incohérent.
     */
    public static function fromString(?string $value): ?self
    {
        if (null === $value || !preg_match('/^\d*\.?\d+(,\d*\.?\d+){3}$/', $value)) {
            return null;
        }
        [$x, $y, $width, $height] = array_map(floatval(...), explode(',', $value));
        if ($width < 0.01 || $height < 0.01 || $x + $width > 1.001 || $y + $height > 1.001) {
            return null;
        }

        return new self($x, $y, min($width, 1 - $x), min($height, 1 - $y));
    }

    public function toString(): string
    {
        return implode(',', array_map(static fn (float $v): string => rtrim(rtrim(number_format($v, 5, '.', ''), '0'), '.') ?: '0', [$this->x, $this->y, $this->width, $this->height]));
    }
}
