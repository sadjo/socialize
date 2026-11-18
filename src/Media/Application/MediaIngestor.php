<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Story\Story;
use App\Media\Infrastructure\FfmpegVideoProcessor;
use App\Media\Infrastructure\VipsImageProcessor;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;

/**
 * Range un fichier envoyé, puis fabrique ses copies publiques :
 * tout de suite pour une image, en tâche de fond pour une vidéo.
 */
final readonly class MediaIngestor
{
    public function __construct(
        private FilesystemOperator $originalsStorage,
        private FilesystemOperator $derivativesStorage,
        private VipsImageProcessor $images,
        private FfmpegVideoProcessor $videos,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function attach(Post|Story $owner, File $file, ?string $altText, int $position): MediaAttachment
    {
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $type = MediaRules::typeOf($mimeType) ?? throw new MediaProcessingException(\sprintf('Le format « %s » n’est pas accepté.', $mimeType));

        $extension = $file->guessExtension() ?? (MediaType::Video === $type ? 'mp4' : 'jpg');
        $path = \sprintf('%s/%s.%s', date('Y/m'), Uuid::v7()->toBase58(), $extension);

        $stream = fopen($file->getPathname(), 'r') ?: throw new MediaProcessingException('Le fichier envoyé est illisible.');
        try {
            $this->originalsStorage->writeStream($path, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $media = new MediaAttachment($owner, $type, $position, $path, $mimeType, (int) $file->getSize(), $altText);

        if (MediaType::Image === $type) {
            $this->processImage($media, (string) file_get_contents($file->getPathname()));
        }

        return $media;
    }

    public function processImage(MediaAttachment $media, string $bytes): void
    {
        $result = $this->images->process($bytes);
        $derivatives = [];
        foreach ($result->files as $name => $contents) {
            $derivatives[$name] = $this->derivativePath($media, $name, 'webp');
            $this->derivativesStorage->write($derivatives[$name], $contents);
        }

        $media->markReady($result->width, $result->height, $derivatives, $result->exif, $result->blurhash);
    }

    /**
     * Transcodage d'une vidéo, appelé par le consommateur Messenger.
     */
    /**
     * @param ?callable(int): void $onProgress pourcentage de la conversion, au fil de l'eau
     */
    public function processVideo(MediaAttachment $media, ?callable $onProgress = null): void
    {
        $dir = sys_get_temp_dir().'/socialize-'.$media->getId()->toBase58();
        $this->filesystem->mkdir($dir);

        try {
            $input = $dir.'/input';
            $stream = $this->originalsStorage->readStream($media->getOriginalPath());
            $this->filesystem->dumpFile($input, $stream);

            $output = $dir.'/video.mp4';
            $posterFile = $dir.'/poster.jpg';
            $this->videos->transcode($input, $output, $onProgress);
            $info = $this->videos->probe($output);
            $this->videos->poster($output, $posterFile, $info['duration']);

            $poster = $this->images->process((string) file_get_contents($posterFile));

            $derivatives = [MediaAttachment::DERIVATIVE_VIDEO => $this->derivativePath($media, 'video', 'mp4')];
            $videoStream = fopen($output, 'r') ?: throw new MediaProcessingException('Vidéo transcodée introuvable.');
            $this->derivativesStorage->writeStream($derivatives[MediaAttachment::DERIVATIVE_VIDEO], $videoStream);
            if (\is_resource($videoStream)) {
                fclose($videoStream);
            }
            $derivatives[MediaAttachment::DERIVATIVE_POSTER] = $this->derivativePath($media, 'poster', 'webp');
            $this->derivativesStorage->write($derivatives[MediaAttachment::DERIVATIVE_POSTER], $poster->files[MediaAttachment::DERIVATIVE_LARGE]);
            // Vignettes de la grille, dans toutes leurs largeurs.
            foreach (ImageSizes::videoNames() as $name) {
                $derivatives[$name] = $this->derivativePath($media, $name, 'webp');
                $this->derivativesStorage->write($derivatives[$name], $poster->files[$name]);
            }

            // L'EXIF déjà connu (archive importée) est gardé : une vidéo transcodée n'en a pas.
            $media->markReady($info['width'], $info['height'], $derivatives, $media->getExif(), $poster->blurhash, $info['duration']);
        } finally {
            $this->filesystem->remove($dir);
        }
    }

    /**
     * Fabrique les copies qui manquent à un média prêt (tailles ajoutées depuis sa publication) : depuis l'original
     * pour une image, depuis l'affiche pour une vidéo. Renvoie le nombre de copies ajoutées.
     */
    public function completeSizes(MediaAttachment $media): int
    {
        if ($media->isRemote() || !$media->isReady()) {
            return 0;
        }
        $expected = $media->isVideo() ? ImageSizes::videoNames() : ImageSizes::names();
        $missing = array_values(array_filter($expected, static fn (string $name): bool => null === $media->getDerivative($name)));
        if ([] === $missing) {
            return 0;
        }
        if ($media->isVideo()) {
            $poster = $media->getDerivative(MediaAttachment::DERIVATIVE_POSTER);
            if (null === $poster) {
                return 0;
            }
            $source = $this->derivativesStorage->read($poster);
        } else {
            $source = $this->originalsStorage->read($media->getOriginalPath());
        }

        $derivatives = [];
        foreach ($this->images->derive($source, $missing) as $name => $contents) {
            $derivatives[$name] = $this->derivativePath($media, $name, 'webp');
            $this->derivativesStorage->write($derivatives[$name], $contents);
        }
        $media->addDerivatives($derivatives);

        return \count($derivatives);
    }

    /**
     * Supprime l'original et les copies d'un média.
     */
    public function delete(MediaAttachment $media): void
    {
        if ($media->isRemote()) {
            return;
        }
        $this->originalsStorage->delete($media->getOriginalPath());
        foreach ($media->getDerivatives() as $path) {
            $this->derivativesStorage->delete($path);
        }
    }

    private function derivativePath(MediaAttachment $media, string $name, string $extension): string
    {
        return \sprintf('%s/%s-%s.%s', $media->getCreatedAt()->format('Y/m'), $media->getId()->toBase58(), $name, $extension);
    }
}
