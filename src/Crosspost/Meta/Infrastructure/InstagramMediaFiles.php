<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Infrastructure;

use App\Core\Domain\Media\MediaAttachment;
use App\Crosspost\Meta\Application\InstagramException;
use App\Media\Application\MediaUrls;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Image;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Médias tels qu'Instagram les télécharge : adresses publiques absolues (DEFAULT_URI), et copies JPEG temporaires
 * des photos (Instagram n'accepte que le JPEG), mises aux proportions voulues par ajout de bandes, sans recadrage.
 * Les copies vont dans l'espace public des médias (servi sous /m/), sous crosspost/{envoi}/, et sont supprimées
 * une fois la publication faite ou abandonnée.
 */
final readonly class InstagramMediaFiles
{
    /** Proportions acceptées par Instagram pour une photo du fil (largeur / hauteur). */
    public const float MIN_RATIO = 0.8;
    public const float MAX_RATIO = 1.91;
    /** Story : 9:16. */
    public const float STORY_RATIO = 9 / 16;
    private const int MAX_WIDTH = 1440;

    private string $base;

    public function __construct(
        private FilesystemOperator $derivativesStorage,
        private MediaUrls $urls,
        #[Autowire(env: 'DEFAULT_URI')]
        string $defaultUri,
    ) {
        $parts = parse_url($defaultUri);
        $this->base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'localhost').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Proportions d'une photo ramenées dans celles qu'accepte le fil d'Instagram.
     */
    public static function feedRatio(MediaAttachment $media): float
    {
        $width = $media->getWidth();
        $height = $media->getHeight();
        $ratio = null !== $width && null !== $height && $height > 0 ? $width / $height : 1.0;

        return max(self::MIN_RATIO, min(self::MAX_RATIO, $ratio));
    }

    /**
     * Copie JPEG de la photo (sa copie publique en grand), complétée de bandes noires jusqu'aux proportions $ratio.
     *
     * @return array{url: string, path: string}
     */
    public function jpeg(MediaAttachment $media, string $folder, int $position, float $ratio): array
    {
        $source = $media->getDerivative(MediaAttachment::DERIVATIVE_LARGE) ?? throw new InstagramException('Une photo n’a pas de copie prête à envoyer.');
        try {
            $image = Image::newFromBuffer($this->derivativesStorage->read($source));
            if ($image->hasAlpha()) {
                $image = $image->flatten(['background' => [0, 0, 0]]);
            }
            $image = $image->colourspace('srgb');
            $width = $image->width;
            $height = $image->height;
            if ($width / $height < $ratio - 0.005) {
                $wide = (int) round($height * $ratio);
                $image = $image->embed(intdiv($wide - $width, 2), 0, $wide, $height, ['extend' => 'background', 'background' => [0, 0, 0]]);
            } elseif ($width / $height > $ratio + 0.005) {
                $tall = (int) round($width / $ratio);
                $image = $image->embed(0, intdiv($tall - $height, 2), $width, $tall, ['extend' => 'background', 'background' => [0, 0, 0]]);
            }
            if ($image->width > self::MAX_WIDTH) {
                $image = $image->resize(self::MAX_WIDTH / $image->width);
            }
            $bytes = $image->writeToBuffer('.jpg', ['Q' => 90, 'strip' => true, 'optimize_coding' => true]);
            $path = \sprintf('crosspost/%s/%02d.jpg', $folder, $position);
            $this->derivativesStorage->write($path, $bytes);
        } catch (VipsException|FilesystemException $e) {
            throw new InstagramException('Une photo n’a pas pu être préparée pour Instagram.', previous: $e);
        }

        return ['url' => $this->absolute($this->urls->path($path)), 'path' => $path];
    }

    /**
     * Adresse publique absolue de la vidéo MP4 (H.264, AAC, index en tête de fichier).
     */
    public function videoUrl(MediaAttachment $media): string
    {
        $path = $this->urls->derivative($media, MediaAttachment::DERIVATIVE_VIDEO) ?? throw new InstagramException('Une vidéo n’est pas encore prête à envoyer.');

        return $this->absolute($path);
    }

    /**
     * Supprime les copies temporaires d'un envoi.
     */
    public function remove(string $folder): void
    {
        try {
            $this->derivativesStorage->deleteDirectory('crosspost/'.$folder);
        } catch (FilesystemException) {
            // Rien à supprimer, ou déjà supprimé.
        }
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : $this->base.$path;
    }
}
