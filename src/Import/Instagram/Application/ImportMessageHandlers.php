<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

use App\Import\Instagram\Domain\ImportStatus;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Infrastructure\ArchiveReader;
use App\Import\Instagram\Infrastructure\Doctrine\InstagramImportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tâches de fond de l'import : analyse de l'archive, puis import lot par lot.
 */
final readonly class ImportMessageHandlers
{
    public function __construct(
        private InstagramImportRepository $imports,
        private ArchiveAnalyzer $analyzer,
        private ArchiveImporter $importer,
        private ArchiveUploads $uploads,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function analyze(AnalyzeInstagramArchive $message): void
    {
        $import = $this->imports->find(Uuid::fromString($message->importId));
        if (!$import instanceof InstagramImport || ImportStatus::Analyzing !== $import->getStatus()) {
            return;
        }
        try {
            $archive = new ArchiveReader(array_column($import->getParts(), 'path'));
            try {
                $this->analyzer->analyze($import, $archive);
            } finally {
                $archive->close();
            }
        } catch (ImportException $e) {
            $this->failed($import, $e->getMessage());
        }
    }

    #[AsMessageHandler]
    public function importBatch(ImportInstagramBatch $message): void
    {
        $import = $this->imports->find(Uuid::fromString($message->importId));
        if (!$import instanceof InstagramImport) {
            return;
        }
        try {
            $more = $this->importer->importBatch($import);
        } catch (ImportException $e) {
            $this->failed($import, $e->getMessage());

            return;
        }
        // Mémoire libérée entre deux lots : un import peut compter des milliers d'éléments.
        $this->em->clear();
        if ($more) {
            $this->bus->dispatch(new ImportInstagramBatch($message->importId));
        }
    }

    private function failed(InstagramImport $import, string $reason): void
    {
        $this->logger->warning('Import Instagram en échec', ['import' => $import->getPublicId(), 'reason' => $reason]);
        $import->fail($reason, $this->clock->now());
        $this->em->flush();
        $this->uploads->cleanUp($import);
        // Les vidéos déjà importées sont tout de même converties.
        $this->importer->convertVideos($import);
    }
}
