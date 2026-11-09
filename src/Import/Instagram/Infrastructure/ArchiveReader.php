<?php

declare(strict_types=1);

namespace App\Import\Instagram\Infrastructure;

use App\Import\Instagram\Application\ImportException;

/**
 * Lecture d'une archive Instagram en une ou plusieurs parties (.zip), sans tout extraire.
 * Les fichiers sont cherchés par leur nom, quelle que soit la version de l'export.
 */
final class ArchiveReader
{
    /** @var array<string, \ZipArchive> */
    private array $zips = [];

    /** @var array<string, string> entrée → partie qui la contient */
    private array $entries = [];

    /**
     * @param list<string> $paths
     */
    public function __construct(array $paths)
    {
        foreach ($paths as $path) {
            $zip = new \ZipArchive();
            if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
                $this->close();
                throw new ImportException(\sprintf('« %s » n’est pas une archive zip lisible.', basename($path)));
            }
            $this->zips[$path] = $zip;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (\is_string($name) && !str_ends_with($name, '/')) {
                    $this->entries[$name] = $path;
                }
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function has(string $entry): bool
    {
        return isset($this->entries[$entry]);
    }

    /**
     * Entrées dont le nom (sans les dossiers) correspond à l'expression, triées.
     *
     * @return list<string>
     */
    public function find(string $basenamePattern): array
    {
        $found = array_values(array_filter(array_keys($this->entries), static fn (string $e): bool => (bool) preg_match($basenamePattern, basename($e))));
        sort($found);

        return $found;
    }

    /**
     * JSON décodé d'une entrée.
     */
    public function json(string $entry): mixed
    {
        try {
            return json_decode($this->read($entry), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ImportException(\sprintf('Le fichier « %s » de l’archive est illisible.', $entry), $e->getCode(), previous: $e);
        }
    }

    public function read(string $entry): string
    {
        $contents = $this->zip($entry)->getFromName($entry);
        if (false === $contents) {
            throw new ImportException(\sprintf('Impossible de lire « %s » dans l’archive.', $entry));
        }

        return $contents;
    }

    /**
     * Copie une entrée dans un fichier, par flux (les vidéos peuvent être lourdes).
     */
    public function extractTo(string $entry, string $target): void
    {
        $in = $this->zip($entry)->getStream($entry);
        $out = fopen($target, 'w');
        if (false === $in || false === $out) {
            throw new ImportException(\sprintf('Impossible d’extraire « %s ».', $entry));
        }
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function close(): void
    {
        foreach ($this->zips as $zip) {
            $zip->close();
        }
        $this->zips = [];
    }

    private function zip(string $entry): \ZipArchive
    {
        $path = $this->entries[$entry] ?? throw new ImportException(\sprintf('« %s » est absent de l’archive.', $entry));

        return $this->zips[$path];
    }
}
