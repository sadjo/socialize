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
 * Remet le fédivers d'accord avec une publication : utile quand des copies sont restées sur d'autres instances
 * (Mastodon…) après un changement de visibilité ou une suppression.
 */
#[AsCommand(name: 'app:federation:resync', description: 'Retire du fédivers les anciennes copies d’une publication, puis la renvoie si elle y est visible')]
final readonly class ResyncPostCommand
{
    /** Révisions retirées par défaut pour une publication supprimée dont l'adresse ne donne pas la dernière. */
    private const int DEFAULT_REVISIONS = 10;

    public function __construct(
        private PostRepository $posts,
        private UserRepository $users,
        private Outbox $outbox,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Identifiant ou adresse de la publication (https://…/p/1Ch1…/v4)')] string $publication,
        #[Option(description: 'Publication supprimée : compte qui l’avait publiée (adresse e-mail ou nom d’utilisateur)')] ?string $compte = null,
        #[Option(description: 'Publication supprimée : nombre de révisions à retirer (/p/…, /v2…), si l’adresse ne le donne pas')] ?int $revisions = null,
    ): int {
        if (!preg_match('#(?:^|/p/)([1-9A-HJ-NP-Za-km-z]{20,24})(?:/v(\d{1,4}))?/?$#', trim($publication), $m)) {
            $io->error('Donnez l’identifiant de la publication ou son adresse, par exemple https://socialize.example/p/1Ch1rCiDZ7zSfRdPMRVyPC/v4.');

            return Command::FAILURE;
        }
        $publicId = $m[1];
        $post = $this->posts->findByPublicId($publicId);

        if ($post instanceof Post) {
            if (!$post->getAccount()->isLocal()) {
                $io->error('Cette publication vient d’une autre instance : c’est à elle de la gérer.');

                return Command::FAILURE;
            }
            $count = $post->getApRevision();
            $this->outbox->resync($post);
            $io->success(\sprintf(
                '%d identifiant%s retiré%s du fédivers%s.',
                $count, $count > 1 ? 's' : '', $count > 1 ? 's' : '',
                $post->isFederable() ? ' ; la publication est renvoyée sous un nouvel identifiant' : ' (la publication n’y est plus visible : rien n’est renvoyé)',
            ));

            return Command::SUCCESS;
        }

        $user = null !== $compte ? $this->users->loadUserByIdentifier($compte) : null;
        if (!$user instanceof User) {
            $io->error('Cette publication n’existe plus : précisez le compte qui l’avait publiée avec --compte=<nom d’utilisateur>.');

            return Command::FAILURE;
        }
        $count = $this->outbox->retractDeleted($user->getAccount(), $publicId, max(1, $revisions ?? (isset($m[2]) ? (int) $m[2] : self::DEFAULT_REVISIONS)));
        $io->success(\sprintf('%d identifiant%s de la publication supprimée retiré%s du fédivers.', $count, $count > 1 ? 's' : '', $count > 1 ? 's' : ''));

        return Command::SUCCESS;
    }
}
