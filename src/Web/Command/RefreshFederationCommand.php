<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Application\Post\Links;
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
 * Renvoie au fédivers le contenu actuel des publications d'un compte, sous leur identifiant (Update) : par exemple pour
 * que les adresses web des anciennes légendes y deviennent des liens. Par défaut, seulement les publications dont la
 * légende contient une adresse web.
 *
 * Effets : rien n'arrive en double chez les abonnés et les J'aime, partages et réponses restent ; mais Mastodon marque
 * chaque publication renvoyée comme « modifiée », avec son historique des versions.
 */
#[AsCommand(name: 'app:federation:refresh', description: 'Renvoie au fédivers le contenu actuel des publications d’un compte (mise à jour, sans perdre leurs réactions)')]
final readonly class RefreshFederationCommand
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
        #[Option(description: 'Seulement ces publications (identifiant de l’adresse /p/…), séparées par des virgules')] ?string $publications = null,
        #[Option(description: 'Toutes les publications, et pas seulement celles dont la légende contient une adresse web')] bool $toutes = false,
        #[Option(description: 'Seulement les publications parues depuis cette date (AAAA-MM-JJ)')] ?string $depuis = null,
        #[Option(description: 'Secondes de pause entre deux publications (pour ménager les instances qui les reçoivent)')] int $pause = 1,
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
        $ids = null !== $publications ? array_filter(array_map(trim(...), explode(',', $publications))) : null;

        // Seules les publications présentes dans le fédivers : visibles et annoncées (pas les imports discrets).
        $posts = array_values(array_filter(
            $this->posts->chronologicalOf($user->getAccount(), $since),
            static fn (Post $p): bool => $p->isFederable() && $p->shouldAnnounce()
                && (null === $ids || \in_array($p->getPublicId(), $ids, true))
                && ($toutes || null !== $ids || self::hasLink($p)),
        ));
        if ([] === $posts) {
            $io->success('Aucune publication à renvoyer.');

            return Command::SUCCESS;
        }
        $io->text(\sprintf('%d publication%s à mettre à jour dans le fédivers.', \count($posts), \count($posts) > 1 ? 's' : ''));

        if ($essai) {
            foreach ($posts as $post) {
                $io->text(\sprintf('  %s  /p/%s  %s', $post->getPublishedAt()->format('d/m/Y H:i'), $post->getPublicId(), mb_substr(str_replace("\n", ' ', $post->getCaption()), 0, 60)));
            }
            $io->note('Essai : rien n’a été envoyé. Relancez sans --essai pour mettre à jour ces publications (Mastodon les marquera « modifiées »).');

            return Command::SUCCESS;
        }

        $io->progressStart(\count($posts));
        foreach ($posts as $i => $post) {
            if ($i > 0 && $pause > 0) {
                sleep($pause);
            }
            $this->outbox->refresh($post);
            $io->progressAdvance();
        }
        $io->progressFinish();
        $io->success(\sprintf('%d publication%s mise%s à jour (les envois partent en tâche de fond).', \count($posts), \count($posts) > 1 ? 's' : '', \count($posts) > 1 ? 's' : ''));

        return Command::SUCCESS;
    }

    private static function hasLink(Post $post): bool
    {
        return array_any(Links::split($post->getCaption()), static fn (array $part): bool => $part[0]);
    }
}
