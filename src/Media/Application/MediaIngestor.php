<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaType;
use App\Core\Domain\Post\Post;
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

    public function attach(Post $post, File $file, ?string $altText, int $position): MediaAttachment
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

        $media = new MediaAttachment($post, $type, $position, $path, $mimeType, (int) $file->getSize(), $altText);

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
    public function processVideo(MediaAttachment $media): void
    {
        $dir = sys_get_temp_dir().'/socialize-'.$media->getId()->toBase58();
        $this->filesystem->mkdir($dir);

        try {
            $input = $dir.'/input';
            $stream = $this->originalsStorage->readStream($media->getOriginalPath());
            $this->filesystem->dumpFile($input, $stream);

            $output = $dir.'/video.mp4';
            $posterFile = $dir.'/poster.jpg';
            $this->videos->transcode($input, $output);
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
            $derivatives[MediaAttachment::DERIVATIVE_THUMB] = $this->derivativePath($media, 'thumb', 'webp');
            $this->derivativesStorage->write($derivatives[MediaAttachment::DERIVATIVE_THUMB], $poster->files[MediaAttachment::DERIVATIVE_THUMB]);

            $media->markReady($info['width'], $info['height'], $derivatives, [], $poster->blurhash, $info['duration']);
        } finally {
            $this->filesystem->remove($dir);
        }
    }

    /**
     * Supprime l'original et les copies d'un média.
     */
    public function delete(MediaAttachment $media): void
    {
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
