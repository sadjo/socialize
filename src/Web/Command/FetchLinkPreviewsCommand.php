<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Post\Post;
use App\Federation\Application\LinkPreviews;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publications texte reçues ou publiées avant les aperçus de liens : demande l'aperçu de leur premier lien aux tâches
 * de fond (file « federation »). À lancer une fois après la mise à jour ; relancée, elle ne redemande que ce qui n'a
 * jamais été demandé.
 */
#[AsCommand(name: 'app:link-previews:fetch', description: 'Demande les aperçus de liens des publications texte déjà présentes')]
final readonly class FetchLinkPreviewsCommand
{
    private const int BATCH = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private LinkPreviews $previews,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        /** @var list<\Symfony\Component\Uid\Uuid> $ids */
        $ids = $this->em->createQueryBuilder()
            ->select('p.id')->from(Post::class, 'p')
            ->where('p.previewState IS NULL')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.MediaAttachment::class.' m WHERE m.post = p)')
            ->getQuery()->getSingleColumnResult();

        $queued = 0;
        foreach ($ids as $i => $id) {
            $post = $this->em->find(Post::class, $id);
            if ($post instanceof Post) {
                $this->previews->refresh($post);
                $queued += null !== $post->getPreviewUrl() ? 1 : 0;
            }
            if (0 === ($i + 1) % self::BATCH) {
                $this->em->clear();
            }
        }
        $this->em->clear();

        $seen = \count($ids);
        $io->success(\sprintf('%d publication%s texte vérifiée%s, %d aperçu%s demandé%s aux tâches de fond.',
            $seen, $seen > 1 ? 's' : '', $seen > 1 ? 's' : '',
            $queued, $queued > 1 ? 's' : '', $queued > 1 ? 's' : ''));

        return Command::SUCCESS;
    }
}
