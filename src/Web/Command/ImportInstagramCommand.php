<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Import\Instagram\Application\ArchiveAnalyzer;
use App\Import\Instagram\Application\ArchiveImporter;
use App\Import\Instagram\Application\ArchiveUploads;
use App\Import\Instagram\Application\ImportException;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Domain\Placement;
use App\Import\Instagram\Infrastructure\ArchiveReader;
use App\Import\Instagram\Infrastructure\Doctrine\InstagramImportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe une archive Instagram déjà présente sur le serveur (utile pour les très grosses archives).
 * Les fichiers donnés ne sont jamais supprimés.
 */
#[AsCommand(name: 'app:import:instagram', description: 'Importe une archive Instagram (.zip, une ou plusieurs parties) dans un compte')]
final readonly class ImportInstagramCommand
{
    public function __construct(
        private UserRepository $users,
        private ArchiveUploads $uploads,
        private ArchiveAnalyzer $analyzer,
        private ArchiveImporter $importer,
        private InstagramImportRepository $imports,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $archives
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Adresse e-mail ou nom d’utilisateur du compte')] string $identifier,
        #[Argument(description: 'Fichier(s) .zip de l’archive')] array $archives,
        #[Option(description: 'Visibilité : public_quiet (défaut), public, followers ou private')] string $visibility = 'public_quiet',
        #[Option(description: 'Publier à la date d’aujourd’hui plutôt qu’à la date d’origine')] bool $today = false,
        #[Option(description: 'Ne pas importer les stories')] bool $withoutStories = false,
    ): int {
        $user = $this->users->loadUserByIdentifier($identifier);
        $placement = Placement::tryFrom($visibility);
        if (!$user instanceof User) {
            $io->error(\sprintf('Aucun compte ne correspond à « %s ».', $identifier));

            return Command::FAILURE;
        }
        if (!\in_array($placement, Placement::forWholeImport(), true)) {
            $io->error('Visibilité inconnue : public_quiet, public, followers ou private.');

            return Command::FAILURE;
        }

        try {
            $import = $this->uploads->fromFiles($user->getAccount(), $archives);
            $archive = new ArchiveReader(array_column($import->getParts(), 'path'));
            try {
                $this->analyzer->analyze($import, $archive);
            } finally {
                $archive->close();
            }
            $counts = $import->getCounts();
            $io->text(\sprintf('Analyse : %d publications, %d archivées, %d stories, %d médias.', $counts['posts'] ?? 0, $counts['archived'] ?? 0, $counts['stories'] ?? 0, $counts['media'] ?? 0));
            foreach ($import->getWarnings() as $warning) {
                $io->warning($warning);
            }

            $import->start($placement, !$today, !$withoutStories);
            $this->em->flush();
            $id = $import->getId();
            $progress = $io->createProgressBar(array_sum(array_intersect_key($counts, ['posts' => 1, 'archived' => 1, 'stories' => 1])));
            do {
                $import = $this->imports->find($id) ?? throw new ImportException('Import introuvable.');
                $more = $this->importer->importBatch($import);
                $progress->advance(ArchiveImporter::BATCH_SIZE);
                $this->em->clear();
            } while ($more);
            $progress->finish();
            $io->newLine(2);
        } catch (ImportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $import = $this->imports->find($id);
        if ($import instanceof InstagramImport) {
            $io->success(array_last($import->getLog()) ?? 'Import terminé.');
        }

        return Command::SUCCESS;
    }
}
