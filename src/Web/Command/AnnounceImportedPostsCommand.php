<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Application\Post\ImportedPostsAnnouncer;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import:announce', description: 'Envoie aux abonnés du fédivers les publications importées sans annonce (import « public discret »)')]
final readonly class AnnounceImportedPostsCommand
{
    public function __construct(
        private UserRepository $users,
        private ImportedPostsAnnouncer $announcer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Adresse e-mail ou nom d’utilisateur du compte')] string $identifier,
        #[Option(description: 'Afficher ce qui serait envoyé, sans rien envoyer')] bool $dryRun = false,
        #[Option(description: 'Pause entre deux publications, en secondes, pour ne pas inonder les instances distantes')] float $pause = 1.0,
    ): int {
        $user = $this->users->loadUserByIdentifier($identifier);
        if (!$user instanceof User) {
            $io->error(\sprintf('Aucun compte ne correspond à « %s ».', $identifier));

            return Command::FAILURE;
        }

        $posts = $this->announcer->pending($user->getAccount());
        if ([] === $posts) {
            $io->success('Aucune publication importée à annoncer : toutes l’ont déjà été (ou sont privées, ou archivées).');

            return Command::SUCCESS;
        }
        $io->text(\sprintf(
            '%d publication%s importée%s à annoncer, du %s au %s.',
            \count($posts), \count($posts) > 1 ? 's' : '', \count($posts) > 1 ? 's' : '',
            $posts[0]->getPublishedAt()->format('d/m/Y'), $posts[\count($posts) - 1]->getPublishedAt()->format('d/m/Y'),
        ));
        if ($dryRun) {
            $io->note('Essai à blanc : rien n’a été envoyé.');

            return Command::SUCCESS;
        }

        $sent = 0;
        $waiting = 0;
        $io->progressStart(\count($posts));
        foreach ($posts as $i => $post) {
            $this->announcer->announce($post) ? ++$sent : ++$waiting;
            $io->progressAdvance();
            if ($pause > 0 && $i < \count($posts) - 1) {
                usleep((int) ($pause * 1_000_000));
            }
        }
        $io->progressFinish();

        $io->success(\sprintf('%d publication%s mise%s en file pour les abonnés du fédivers.', $sent, $sent > 1 ? 's' : '', $sent > 1 ? 's' : ''));
        if ($waiting > 0) {
            $io->note(\sprintf('%d publication%s partiront à la fin de la conversion de leur vidéo.', $waiting, $waiting > 1 ? 's' : ''));
        }

        return Command::SUCCESS;
    }
}
