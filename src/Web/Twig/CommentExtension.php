<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Web\Feed\CommentPreviews;
use Twig\Attribute\AsTwigFunction;

final readonly class CommentExtension
{
    public function __construct(
        private CommentPreviews $previews,
    ) {
    }

    /**
     * Derniers commentaires d'une publication, pour l'aperçu dans le fil : préchargés pour toute la page par le
     * contrôleur (CommentPreviews::prime()), sinon lus pour cette publication seule.
     *
     * @return list<Comment>
     */
    #[AsTwigFunction('recent_comments')]
    public function recentComments(Post $post, int $limit = CommentPreviews::SIZE): array
    {
        return $this->previews->of($post, $limit);
    }
}
