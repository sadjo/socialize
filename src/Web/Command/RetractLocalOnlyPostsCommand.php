<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Application\Outbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seules les publications publiques et réservées aux abonnés partent dans le fédivers. Les publications non
 * répertoriées et réservées à des groupes y partaient auparavant : cette commande retire leurs copies (Delete, de
 * tous leurs identifiants, chez les abonnés distants). Elles restent visibles ici. À lancer une fois.
 */
#[AsCommand(name: 'app:federation:retract-local-only', description: 'Retire du fédivers les publications non répertoriées et réservées à des groupes, qui restent désormais sur l’instance')]
final readonly class RetractLocalOnlyPostsCommand
{
    public function __construct(
        private PostRepository $posts,
        private Outbox $outbox,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Afficher les publications concernées sans rien retirer')] bool $simulation = false,
    ): int {
        /** @var list<Post> $posts */
        $posts = array_values(array_filter(
            $this->posts->findBy(['visibility' => [Visibility::Unlisted, Visibility::Groups]], ['publishedAt' => 'ASC']),
            // Une publication archivée a déjà été retirée, une publication en préparation n'est jamais partie.
            static fn (Post $post): bool => $post->getAccount()->isLocal() && !$post->isArchived() && !$post->isPending(),
        ));
        if ([] === $posts) {
            $io->success('Aucune publication non répertoriée ou réservée à des groupes à retirer du fédivers.');

            return Command::SUCCESS;
        }
        $io->text(\sprintf('%d publication%s non répertoriée%s ou réservée%s à des groupes.', \count($posts), ...array_fill(0, 3, \count($posts) > 1 ? 's' : '')));
        if ($simulation) {
            foreach ($posts as $post) {
                $io->text(\sprintf('  @%s · %s · %s', $post->getAccount()->getUsername(), $post->getPublishedAt()->format('Y-m-d'), $post->getVisibility()->label()));
            }
            $io->note('Simulation : rien n’a été retiré.');

            return Command::SUCCESS;
        }

        foreach ($io->progressIterate($posts) as $post) {
            $this->outbox->resync($post);
            $this->em->clear();
        }
        $io->success(\sprintf('%d publication%s retirée%s du fédivers ; elles restent visibles sur l’instance.', \count($posts), ...array_fill(0, 2, \count($posts) > 1 ? 's' : '')));

        return Command::SUCCESS;
    }
}
