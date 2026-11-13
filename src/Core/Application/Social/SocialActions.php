<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\AccountFollowed;
use App\Core\Domain\Event\AccountUnfollowed;
use App\Core\Domain\Event\CommentAdded;
use App\Core\Domain\Event\CommentDeleting;
use App\Core\Domain\Event\FollowerRemoved;
use App\Core\Domain\Event\PostLiked;
use App\Core\Domain\Event\PostUnliked;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use App\Core\Domain\Social\Like;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Interactions entre comptes : suivre, aimer, commenter.
 */
final readonly class SocialActions
{
    public function __construct(
        private EntityManagerInterface $em,
        private FollowRepository $follows,
        private LikeRepository $likes,
        private EventDispatcherInterface $events,
        private \App\Core\Application\Notification\Notifications $notifications,
    ) {
    }

    public function follow(Account $follower, Account $following): void
    {
        if ($this->follows->findBetween($follower, $following) instanceof Follow) {
            return;
        }
        // Un compte distant doit accepter l'abonnement (activité Accept) avant qu'il soit effectif.
        $follow = new Follow($follower, $following, $following->isLocal() ? FollowState::Accepted : FollowState::Pending);
        $this->em->persist($follow);
        $this->em->flush();
        $this->events->dispatch(new AccountFollowed($follow));
    }

    public function unfollow(Account $follower, Account $following): void
    {
        $follow = $this->follows->findBetween($follower, $following);
        if ($follow instanceof Follow) {
            $apUri = $follow->getApUri();
            $this->em->remove($follow);
            $this->em->flush();
            $this->events->dispatch(new AccountUnfollowed($follower, $following, $apUri));
        }
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
            $apUri = $like->getApUri();
            $this->em->remove($like);
            $post->decrementLikes();
            $this->em->flush();
            $this->events->dispatch(new PostUnliked($account, $post, $apUri));
            $this->notifications->unliked($account, $post);

            return false;
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

        $comment = new Comment($post, $account, $content);
        $this->em->persist($comment);
        $post->incrementComments();
        $this->em->flush();
        $this->events->dispatch(new CommentAdded($comment));
        $this->notifications->commented($comment);

        return $comment;
    }

    public function deleteComment(Comment $comment): void
    {
        $this->events->dispatch(new CommentDeleting($comment));
        $comment->getPost()->decrementComments();
        $this->em->remove($comment);
        $this->em->flush();
    }
}
