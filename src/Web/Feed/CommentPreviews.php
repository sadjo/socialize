<?php

declare(strict_types=1);

namespace App\Web\Feed;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Aperçu des commentaires sous les publications d'un fil : chargé en une requête pour toute la page (prime()), au
 * lieu d'une requête par publication. Une publication non préchargée (page d'une seule publication…) est lue seule.
 * Gardé le temps d'une requête (reset() en mode worker).
 */
final class CommentPreviews implements ResetInterface
{
    public const int SIZE = 2;

    /** @var array<string, list<Comment>> */
    private array $previews = [];

    public function __construct(
        private readonly CommentRepository $comments,
    ) {
    }

    /**
     * @param iterable<Post> $posts
     */
    public function prime(iterable $posts): void
    {
        $commented = [];
        foreach ($posts as $post) {
            if ($post->getCommentsCount() > 0 && !\array_key_exists($post->getPublicId(), $this->previews)) {
                $commented[] = $post;
            }
        }
        if ([] === $commented) {
            return;
        }

        $found = $this->comments->latestForPosts($commented, self::SIZE);
        foreach ($commented as $post) {
            $this->previews[$post->getPublicId()] = $found[$post->getPublicId()] ?? [];
        }
    }

    /**
     * @return list<Comment>
     */
    public function of(Post $post, int $limit = self::SIZE): array
    {
        if (0 === $post->getCommentsCount()) {
            return [];
        }
        if (self::SIZE === $limit && \array_key_exists($post->getPublicId(), $this->previews)) {
            return $this->previews[$post->getPublicId()];
        }

        return $this->comments->latestFor($post, $limit);
    }

    public function reset(): void
    {
        $this->previews = [];
    }
}
