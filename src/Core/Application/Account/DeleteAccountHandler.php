<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\AccountDeleting;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Supprime un compte de l'instance et tout ce qu'il a publié : les autres modules effacent d'abord ses fichiers et
 * préviennent le fédivers (AccountDeleting) ; les compteurs des publications qu'il aimait ou commentait sont
 * corrigés ; la base efface ensuite le reste en cascade (publications, stories, commentaires, J'aime, abonnements,
 * groupes, notifications, connexions aux photothèques…).
 */
#[AsMessageHandler]
final readonly class DeleteAccountHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LikeRepository $likes,
        private CommentRepository $comments,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(DeleteAccount $message): void
    {
        $account = $this->em->find(Account::class, Uuid::fromString($message->accountId));
        if (!$account instanceof Account || !$account->isLocal()) {
            return;
        }
        $this->events->dispatch(new AccountDeleting($account));

        foreach ($this->likes->findBy(['account' => $account]) as $like) {
            $like->getPost()->decrementLikes();
        }
        foreach ($this->comments->findBy(['account' => $account]) as $comment) {
            $comment->getPost()->decrementComments();
        }
        $this->em->remove($account);
        $this->em->flush();
    }
}
