<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaStatus;
use App\Media\Application\MediaIngestor;
use App\Media\Application\MediaProcessingException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fabrique les tailles de copies ajoutées depuis la publication des médias (images en 640 et 960 pixels, vignettes
 * de grille en 240, 360 et 720) : le navigateur choisit ensuite la plus proche de l'affichage. À lancer une fois
 * après la mise à jour ; relancée, elle ne refait que ce qui manque encore.
 */
#[AsCommand(name: 'app:media:complete-sizes', description: 'Fabrique les tailles de copies qui manquent aux photos et vidéos déjà publiées')]
final readonly class CompleteMediaSizesCommand
{
    /** Médias traités entre deux écritures en base (la mémoire est libérée à chaque fois). */
    private const int BATCH = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private MediaIngestor $ingestor,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $query = $this->em->createQueryBuilder()
            ->select('m')->from(MediaAttachment::class, 'm')
            ->where('m.status = :ready')->andWhere('m.remoteUrl IS NULL')
            ->setParameter('ready', MediaStatus::Ready)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery();

        $seen = $completed = $copies = $failed = 0;
        $progress = $io->createProgressBar();
        foreach ($query->toIterable() as $media) {
            ++$seen;
            try {
                $added = $this->ingestor->completeSizes($media);
                if ($added > 0) {
                    ++$completed;
                    $copies += $added;
                }
            } catch (MediaProcessingException|FilesystemException $e) {
                ++$failed;
                $io->warning(\sprintf('Média %s : %s', $media->getId()->toBase58(), $e->getMessage()));
            }
            $progress->advance();
            if (0 === $seen % self::BATCH) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();
        $this->em->clear();
        $progress->finish();
        $io->newLine(2);

        $io->success(\sprintf('%d média%s vérifié%s, %d complété%s (%d copie%s ajoutée%s).',
            $seen, $seen > 1 ? 's' : '', $seen > 1 ? 's' : '',
            $completed, $completed > 1 ? 's' : '',
            $copies, $copies > 1 ? 's' : '', $copies > 1 ? 's' : ''));
        if ($failed > 0) {
            $io->warning(\sprintf('%d média%s n’%s pas pu être traité%s (voir ci-dessus).', $failed, $failed > 1 ? 's' : '', $failed > 1 ? 'ont' : 'a', $failed > 1 ? 's' : ''));
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
