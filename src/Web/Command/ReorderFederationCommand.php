<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Domain\Account\User;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Federation\Application\Outbox;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remet dans l'ordre chronologique les publications d'un compte telles que le fédivers les range. Mastodon range un
 * message reçu en direct au moment de sa réception : une publication renvoyée (changement de visibilité, de date)
 * remonte en tête du profil. Chaque publication visible dans le fédivers y est retirée puis renvoyée, de la plus
 * ancienne à la plus récente, avec une pause entre deux pour qu'elles arrivent dans cet ordre.
 *
 * Effets : chaque publication renvoyée arrive chez les abonnés comme un nouveau message, et les J'aime, partages et
 * réponses faits sur les autres instances y sont perdus (nouvel identifiant).
 */
#[AsCommand(name: 'app:federation:reorder', description: 'Renvoie au fédivers, dans l’ordre chronologique, les publications d’un compte')]
final readonly class ReorderFederationCommand
{
    public function __construct(
        private PostRepository $posts,
        private UserRepository $users,
        private Outbox $outbox,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Compte (nom d’utilisateur ou adresse e-mail)')] string $compte,
        #[Option(description: 'Seulement les publications parues depuis cette date (AAAA-MM-JJ), et les suivantes')] ?string $depuis = null,
        #[Option(description: 'Secondes de pause entre deux publications (pour qu’elles arrivent dans l’ordre)')] int $pause = 5,
        #[Option(description: 'Montre ce qui serait renvoyé, sans rien envoyer')] bool $essai = false,
    ): int {
        $user = $this->users->loadUserByIdentifier($compte);
        if (!$user instanceof User) {
            $io->error('Compte introuvable.');

            return Command::FAILURE;
        }
        $since = null;
        if (null !== $depuis) {
            $since = \DateTimeImmutable::createFromFormat('!Y-m-d', $depuis);
            if (false === $since) {
                $io->error('Date attendue au format AAAA-MM-JJ, par exemple 2025-09-05.');

                return Command::FAILURE;
            }
        }

        // Seules les publications présentes dans le fédivers : visibles et annoncées (pas les imports discrets).
        $posts = array_values(array_filter(
            $this->posts->chronologicalOf($user->getAccount(), $since),
            static fn (Post $p): bool => $p->isFederable() && $p->shouldAnnounce(),
        ));
        if ([] === $posts) {
            $io->success('Aucune publication à renvoyer.');

            return Command::SUCCESS;
        }
        $first = $posts[0]->getPublishedAt()->format('d/m/Y');
        $last = $posts[\count($posts) - 1]->getPublishedAt()->format('d/m/Y');
        $io->text(\sprintf('%d publication%s, du %s au %s, de la plus ancienne à la plus récente.', \count($posts), \count($posts) > 1 ? 's' : '', $first, $last));

        if ($essai) {
            foreach ($posts as $post) {
                $io->text(\sprintf('  %s  /p/%s  %s', $post->getPublishedAt()->format('d/m/Y H:i'), $post->getPublicId(), mb_substr(str_replace("\n", ' ', $post->getCaption()), 0, 60)));
            }
            $io->note('Essai : rien n’a été envoyé. Relancez sans --essai pour renvoyer ces publications.');

            return Command::SUCCESS;
        }

        $io->progressStart(\count($posts));
        foreach ($posts as $i => $post) {
            if ($i > 0 && $pause > 0) {
                sleep($pause);
            }
            $this->outbox->resync($post);
            $io->progressAdvance();
        }
        $io->progressFinish();
        $io->success(\sprintf('%d publication%s renvoyée%s dans l’ordre (les envois partent en tâche de fond).', \count($posts), \count($posts) > 1 ? 's' : '', \count($posts) > 1 ? 's' : ''));

        return Command::SUCCESS;
    }
}
