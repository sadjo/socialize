<?php

declare(strict_types=1);

namespace App\Media\Infrastructure;

use App\Media\Application\MediaProcessingException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Transcode une vidéo en MP4 H.264/AAC lisible partout, et en extrait une affiche.
 * Les métadonnées (dont la position GPS) sont retirées de la copie publique.
 */
final class FfmpegVideoProcessor
{
    private const int TIMEOUT = 3600;

    /**
     * @return array{width: int, height: int, duration: float}
     */
    public function probe(string $path): array
    {
        $process = new Process(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', $path]);
        $process->setTimeout(120);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new MediaProcessingException('Cette vidéo n’a pas pu être lue.', $e->getCode(), previous: $e);
        }

        /** @var array{streams?: list<array{codec_type?: string, width?: int, height?: int}>, format?: array{duration?: string}} $info */
        $info = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($info['streams'] ?? [] as $stream) {
            if ('video' === ($stream['codec_type'] ?? null)) {
                return [
                    'width' => (int) ($stream['width'] ?? 0),
                    'height' => (int) ($stream['height'] ?? 0),
                    'duration' => (float) ($info['format']['duration'] ?? 0),
                ];
            }
        }

        throw new MediaProcessingException('Ce fichier ne contient pas de piste vidéo.');
    }

    public function transcode(string $input, string $output): void
    {
        $command = ['ffmpeg', '-y', '-v', 'error', '-i', $input];
        if ($this->hasAudio($input)) {
            $command = [...$command, '-map', '0:v:0', '-map', '0:a:0'];
        } else {
            // Piste muette ajoutée : sans son, Mastodon traite la vidéo comme un GIF animé,
            // que ses applications affichent mal à côté de photos.
            $command = [...$command, '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000', '-map', '0:v:0', '-map', '1:a:0', '-shortest'];
        }

        $this->run([
            ...$command,
            '-vf', "scale=w='min(1080,iw)':h='min(1920,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k',
            '-map_metadata', '-1', '-movflags', '+faststart',
            $output,
        ]);
    }

    public function hasAudio(string $path): bool
    {
        $process = new Process(['ffprobe', '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);
        $process->setTimeout(120);
        $process->run();

        return $process->isSuccessful() && '' !== trim($process->getOutput());
    }

    public function poster(string $input, string $output, float $duration): void
    {
        $at = $duration > 2 ? 1.0 : 0.0;
        $this->run(['ffmpeg', '-y', '-v', 'error', '-ss', (string) $at, '-i', $input, '-frames:v', '1', '-q:v', '2', $output]);
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new MediaProcessingException('Le traitement de la vidéo a échoué.', $e->getCode(), previous: $e);
        }
    }
}
