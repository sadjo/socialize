<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

use App\Core\Domain\Account\Account;
use App\Import\Instagram\Domain\ImportStatus;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Infrastructure\Doctrine\InstagramImportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Réception d'une archive envoyée par morceaux depuis le navigateur, avec reprise après une coupure.
 */
final readonly class ArchiveUploads
{
    public function __construct(
        private EntityManagerInterface $em,
        private InstagramImportRepository $imports,
        private LockFactory $locks,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Autowire(param: 'app.import_dir')]
        private string $importDir,
        #[Autowire(param: 'app.import_max_size')]
        private int $maxSize,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Commence un envoi, ou reprend celui des mêmes fichiers resté en cours.
     *
     * @param list<array{name: string, size: int}> $parts
     */
    public function begin(Account $account, array $parts): InstagramImport
    {
        $active = $this->imports->activeFor($account);
        if ($active instanceof InstagramImport) {
            $same = array_map(static fn (array $p): array => [$p['name'], $p['size']], $active->getParts()) === array_map(static fn (array $p): array => [$p['name'], $p['size']], $parts);
            if (ImportStatus::Uploading === $active->getStatus() && $same) {
                return $active;
            }
            throw new ImportException('Un import est déjà en cours : terminez-le ou abandonnez-le avant d’en commencer un autre.');
        }
        if ([] === $parts || \count($parts) > InstagramImport::MAX_PARTS) {
            throw new ImportException(\sprintf('Choisissez de 1 à %d fichiers .zip.', InstagramImport::MAX_PARTS));
        }
        if (array_sum(array_column($parts, 'size')) > $this->maxSize) {
            throw new ImportException(\sprintf('L’archive dépasse la taille maximale (%d Go).', intdiv($this->maxSize, 1024 ** 3)));
        }

        $import = new InstagramImport($account, $this->clock->now());
        $dir = $this->dir($import);
        $this->filesystem->mkdir($dir);
        foreach ($parts as $i => $part) {
            $import->addPart(mb_substr($part['name'], 0, 255), $part['size'], \sprintf('%s/part-%d.zip', $dir, $i + 1), true);
            $this->filesystem->touch(\sprintf('%s/part-%d.zip', $dir, $i + 1));
        }
        $this->em->persist($import);
        $this->em->flush();

        return $import;
    }

    /**
     * Ajoute un morceau à une partie. Le verrou évite que deux morceaux s'écrivent en même temps.
     *
     * @param resource $chunk
     *
     * @return int octets reçus pour cette partie
     */
    public function append(InstagramImport $import, int $index, int $offset, $chunk): int
    {
        $lock = $this->locks->createLock('instagram-import-'.$import->getPublicId(), 120);
        $lock->acquire(true);
        try {
            $this->em->refresh($import);
            $part = $import->getParts()[$index] ?? throw new ImportException('Partie inconnue.');
            if (ImportStatus::Uploading !== $import->getStatus()) {
                throw new ImportException('L’archive a déjà été reçue.');
            }
            if ($offset !== $part['received']) {
                throw new ChunkRejected($part['received']);
            }

            $out = fopen($part['path'], 'c') ?: throw new ImportException('Impossible d’écrire l’archive sur le serveur.');
            try {
                fseek($out, $offset);
                $written = (int) stream_copy_to_stream($chunk, $out, $part['size'] - $offset);
                ftruncate($out, max(0, $offset + $written));
            } finally {
                fclose($out);
            }

            $import->receive($index, $written);
            if ($import->isUploaded()) {
                $import->startAnalysis();
            }
            $this->em->flush();
        } finally {
            $lock->release();
        }

        if (ImportStatus::Analyzing === $import->getStatus()) {
            $this->bus->dispatch(new AnalyzeInstagramArchive($import->getId()->toRfc4122()));
        }

        return $import->getParts()[$index]['received'];
    }

    /**
     * Archive déjà présente sur le serveur (commande console) : elle n'est jamais supprimée.
     *
     * @param list<string> $paths
     */
    public function fromFiles(Account $account, array $paths): InstagramImport
    {
        if ($this->imports->activeFor($account) instanceof InstagramImport) {
            throw new ImportException('Un import est déjà en cours pour ce compte.');
        }
        $import = new InstagramImport($account, $this->clock->now());
        foreach ($paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new ImportException(\sprintf('Fichier introuvable : %s', $path));
            }
            $import->addPart(basename($path), (int) filesize($path), $path, false);
        }
        $import->startAnalysis();
        $this->em->persist($import);
        $this->em->flush();

        return $import;
    }

    /**
     * Abandon : l'import et ses fichiers reçus sont supprimés (les publications déjà importées restent).
     */
    public function cancel(InstagramImport $import): void
    {
        $this->cleanUp($import);
        $this->em->remove($import);
        $this->em->flush();
    }

    /**
     * Supprime les fichiers reçus et les aperçus ; le JSON de chaque élément reste en base.
     */
    public function cleanUp(InstagramImport $import): void
    {
        foreach ($import->getParts() as $part) {
            if ($part['owned']) {
                $this->filesystem->remove($part['path']);
            }
        }
        $this->filesystem->remove($this->dir($import));
    }

    public function dir(InstagramImport $import): string
    {
        return $this->importDir.'/'.$import->getPublicId();
    }
}
