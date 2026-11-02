<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\Like;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Interactions entre comptes : suivre, aimer, commenter.
 */
final readonly class SocialActions
{
    public function __construct(
        private EntityManagerInterface $em,
        private FollowRepository $follows,
        private LikeRepository $likes,
    ) {
    }

    public function follow(Account $follower, Account $following): void
    {
        if ($this->follows->findBetween($follower, $following) instanceof Follow) {
            return;
        }
        $this->em->persist(new Follow($follower, $following));
        $this->em->flush();
    }

    public function unfollow(Account $follower, Account $following): void
    {
        $follow = $this->follows->findBetween($follower, $following);
        if ($follow instanceof Follow) {
            $this->em->remove($follow);
            $this->em->flush();
        }
    }

    /**
     * @return bool vrai si la publication est aimée après l'appel
     */
    public function toggleLike(Account $account, Post $post): bool
    {
        $like = $this->likes->findOneFor($account, $post);
        if ($like instanceof Like) {
            $this->em->remove($like);
            $post->decrementLikes();
            $this->em->flush();

            return false;
        }

        $this->em->persist(new Like($account, $post));
        $post->incrementLikes();
        $this->em->flush();

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

        return $comment;
    }

    public function deleteComment(Comment $comment): void
    {
        $comment->getPost()->decrementComments();
        $this->em->remove($comment);
        $this->em->flush();
    }
}
