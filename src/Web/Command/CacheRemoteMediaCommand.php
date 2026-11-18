<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Domain\Media\MediaAttachment;
use App\Federation\Application\CacheRemoteMedia;
use App\Federation\Application\CacheRemoteMediaHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Médias reçus du fédivers avant la copie locale (ou dont la copie a échoué) : demande leurs copies aux tâches de
 * fond (file « media »). À lancer une fois après la mise à jour ; relancée, elle ne redemande que ce qui manque.
 */
#[AsCommand(name: 'app:media:cache-remote', description: 'Demande les copies locales des médias du fédivers déjà reçus')]
final readonly class CacheRemoteMediaCommand
{
    private const int BATCH = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $query = $this->em->createQueryBuilder()
            ->select('m')->from(MediaAttachment::class, 'm')
            ->where('m.remoteUrl IS NOT NULL')
            ->orderBy('m.createdAt', \SortDirection::Descending)
            ->getQuery();

        $seen = $queued = 0;
        foreach ($query->toIterable() as $media) {
            ++$seen;
            if (!$media->hasLocalCopies() && null !== CacheRemoteMediaHandler::sourceOf($media)) {
                $this->bus->dispatch(new CacheRemoteMedia($media->getId()->toRfc4122()));
                ++$queued;
            }
            if (0 === $seen % self::BATCH) {
                $this->em->clear();
            }
        }
        $this->em->clear();

        $io->success(\sprintf('%d média%s du fédivers vérifié%s, %d copie%s demandée%s aux tâches de fond.',
            $seen, $seen > 1 ? 's' : '', $seen > 1 ? 's' : '',
            $queued, $queued > 1 ? 's' : '', $queued > 1 ? 's' : ''));

        return Command::SUCCESS;
    }
}
