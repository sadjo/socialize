<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use Twig\Attribute\AsTwigFunction;

final readonly class CommentExtension
{
    public function __construct(
        private CommentRepository $comments,
    ) {
    }

    /**
     * Derniers commentaires d'une publication, pour l'aperçu dans le fil.
     *
     * @return list<Comment>
     */
    #[AsTwigFunction('recent_comments')]
    public function recentComments(Post $post, int $limit = 2): array
    {
        return $this->comments->latestFor($post, $limit);
    }
}
