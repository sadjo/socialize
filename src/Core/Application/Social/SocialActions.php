<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\AccountFollowed;
use App\Core\Domain\Event\AccountUnfollowed;
use App\Core\Domain\Event\CommentAdded;
use App\Core\Domain\Event\CommentDeleting;
use App\Core\Domain\Event\FollowerRemoved;
use App\Core\Domain\Event\FollowRequestAccepted;
use App\Core\Domain\Event\PostLiked;
use App\Core\Domain\Event\PostUnliked;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use App\Core\Domain\Social\Like;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Interactions entre comptes : suivre, aimer, commenter. Celles d'un compte d'un autre serveur piloté d'ici
 * (connexion Mastodon) passent d'abord par son serveur (PilotedActions), puis sont enregistrées ici pour s'afficher
 * aussitôt ; l'activité fédérée qui arrive ensuite est reconnue, sans doublon.
 */
final readonly class SocialActions
{
    public function __construct(
        private EntityManagerInterface $em,
        private FollowRepository $follows,
        private LikeRepository $likes,
        private CommentRepository $comments,
        private EventDispatcherInterface $events,
        private \App\Core\Application\Notification\Notifications $notifications,
        private PilotedActions $piloted,
    ) {
    }

    public function follow(Account $follower, Account $following): void
    {
        if ($this->follows->findBetween($follower, $following) instanceof Follow) {
            return;
        }
        if ($follower->isLocal()) {
            // Un compte distant doit accepter l'abonnement (activité Accept) avant qu'il soit effectif ; un compte d'ici
            // aussi, s'il valide lui-même ses abonnés.
            $accepted = $following->isLocal() && !$following->manuallyApprovesFollowers();
        } else {
            $accepted = $this->piloted->follow($follower, $following);
            if (null !== $this->follows->findOneBy(['follower' => $follower, 'following' => $following])) {
                return; // déjà reçu par la fédération pendant l'appel
            }
        }
        $follow = new Follow($follower, $following, $accepted ? FollowState::Accepted : FollowState::Pending);
        $this->em->persist($follow);
        $this->em->flush();
        $this->events->dispatch(new AccountFollowed($follow));
    }

    public function unfollow(Account $follower, Account $following): void
    {
        $follow = $this->follows->findBetween($follower, $following);
        if ($follow instanceof Follow) {
            if (!$follower->isLocal()) {
                $this->piloted->unfollow($follower, $following);
            }
            $apUri = $follow->getApUri();
            $this->em->remove($follow);
            $this->em->flush();
            $this->events->dispatch(new AccountUnfollowed($follower, $following, $apUri));
        }
    }

    /**
     * Le compte accepte une demande d'abonnement en attente.
     *
     * @return bool false s'il n'y avait pas de demande en attente
     */
    public function acceptFollower(Account $account, Account $follower): bool
    {
        $follow = $this->follows->findBetween($follower, $account);
        if (!$follow instanceof Follow || $follow->isAccepted()) {
            return false;
        }
        $follow->accept();
        $this->em->flush();
        $this->events->dispatch(new FollowRequestAccepted($follow));

        return true;
    }

    /**
     * Toutes les demandes en attente acceptées (le compte ne valide plus ses abonnés).
     *
     * @return int nombre de demandes acceptées
     */
    public function acceptAllFollowers(Account $account): int
    {
        $accepted = 0;
        foreach ($this->follows->pendingRequestsOf($account) as $follow) {
            $accepted += (int) $this->acceptFollower($account, $follow->getFollower());
        }

        return $accepted;
    }

    /**
     * Le compte retire l'un de ses abonnés (ou refuse une demande en attente).
     */
    public function removeFollower(Account $account, Account $follower): void
    {
        $follow = $this->follows->findBetween($follower, $account);
        if ($follow instanceof Follow) {
            $apUri = $follow->getApUri();
            $this->em->remove($follow);
            $this->em->flush();
            $this->events->dispatch(new FollowerRemoved($account, $follower, $apUri));
        }
    }

    /**
     * @return bool vrai si la publication est aimée après l'appel
     */
    public function toggleLike(Account $account, Post $post): bool
    {
        $like = $this->likes->findOneFor($account, $post);
        if ($like instanceof Like) {
            if ($this->throughServer($account, $post)) {
                $this->piloted->unlike($account, $post);
            }
            $apUri = $like->getApUri();
            $this->em->remove($like);
            $post->decrementLikes();
            $this->em->flush();
            $this->events->dispatch(new PostUnliked($account, $post, $apUri));
            $this->notifications->unliked($account, $post);

            return false;
        }

        if ($this->throughServer($account, $post)) {
            $this->piloted->like($account, $post);
            if ($this->likes->findOneFor($account, $post) instanceof Like) {
                return true; // déjà reçu par la fédération
            }
        }
        $like = new Like($account, $post);
        $this->em->persist($like);
        $post->incrementLikes();
        $this->em->flush();
        $this->events->dispatch(new PostLiked($like));
        $this->notifications->liked($account, $post);

        return true;
    }

    public function like(Account $account, Post $post): void
    {
        if (!$this->likes->findOneFor($account, $post) instanceof Like) {
            $this->toggleLike($account, $post);
        }
    }

    public function comment(Account $account, Post $post, string $content): Comment
    {
        $content = trim($content);
        if ('' === $content || mb_strlen($content) > Comment::MAX_LENGTH) {
            throw new \DomainException(\sprintf('Un commentaire contient entre 1 et %d caractères.', Comment::MAX_LENGTH));
        }

        if (!$this->throughServer($account, $post)) {
            $comment = new Comment($post, $account, $content);
        } else {
            $apUri = $this->piloted->comment($account, $post, $content);
            $comment = $this->comments->findOneBy(['apUri' => $apUri]);
            if ($comment instanceof Comment) {
                return $comment; // déjà reçu par la fédération
            }
            $comment = Comment::federated($post, $account, $content, $apUri, new \DateTimeImmutable());
        }
        $this->em->persist($comment);
        $post->incrementComments();
        $this->em->flush();
        $this->events->dispatch(new CommentAdded($comment));
        $this->notifications->commented($comment);

        return $comment;
    }

    /**
     * L'interaction d'un compte piloté d'ici passe-t-elle par son serveur ? Oui pour une publication du fédivers ou
     * qui y part (publique, abonnés). Non pour une publication restée sur l'instance (non répertoriée, groupes) : son
     * serveur ne la connaît pas, l'interaction reste ici.
     */
    private function throughServer(Account $account, Post $post): bool
    {
        return !$account->isLocal() && (!$post->getAccount()->isLocal() || $post->getVisibility()->isFederated());
    }

    public function deleteComment(Comment $comment, ?Account $by = null): void
    {
        if ($by instanceof Account && !$by->isLocal() && $comment->getAccount()->getId()->equals($by->getId())) {
            $this->piloted->deleteComment($comment);
        }
        $this->events->dispatch(new CommentDeleting($comment));
        $comment->getPost()->decrementComments();
        $this->em->remove($comment);
        $this->em->flush();
    }
}
