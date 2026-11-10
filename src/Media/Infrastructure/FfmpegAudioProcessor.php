<?php

declare(strict_types=1);

namespace App\Media\Infrastructure;

use App\Media\Application\MediaProcessingException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Copie publique d'un morceau de musique : AAC 128 kb/s (.m4a), lisible par tous les navigateurs,
 * sans pochette ni métadonnées.
 */
final class FfmpegAudioProcessor
{
    private const int TIMEOUT = 600;

    /**
     * Durée en secondes ; exception si le fichier n'a pas de piste audio.
     */
    public function duration(string $path): float
    {
        $process = new Process(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', $path]);
        $process->setTimeout(120);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new MediaProcessingException('Ce morceau n’a pas pu être lu.', $e->getCode(), previous: $e);
        }

        /** @var array{streams?: list<array{codec_type?: string}>, format?: array{duration?: string}} $info */
        $info = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($info['streams'] ?? [] as $stream) {
            if ('audio' === ($stream['codec_type'] ?? null)) {
                return (float) ($info['format']['duration'] ?? 0);
            }
        }

        throw new MediaProcessingException('Ce fichier ne contient pas de piste audio.');
    }

    public function transcode(string $input, string $output): void
    {
        $process = new Process([
            'ffmpeg', '-y', '-v', 'error', '-i', $input,
            '-map', '0:a:0', '-vn', '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
            '-map_metadata', '-1', '-movflags', '+faststart', '-f', 'mp4',
            $output,
        ]);
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new MediaProcessingException('La préparation du morceau a échoué.', $e->getCode(), previous: $e);
        }
    }
}
