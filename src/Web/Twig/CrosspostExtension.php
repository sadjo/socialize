<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Story\Story;
use App\Crosspost\Meta\Application\InstagramPublishing;
use App\Crosspost\Meta\Domain\InstagramCrosspost;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramCrosspostRepository;
use Twig\Attribute\AsTwigFunction;

/**
 * État de l'envoi vers Instagram d'une publication ou d'une story (affiché à son auteur).
 */
final readonly class CrosspostExtension
{
    public function __construct(
        private InstagramCrosspostRepository $crossposts,
        private InstagramPublishing $publishing,
    ) {
    }

    /**
     * Bouton « Publier sur Instagram » d'une publication déjà en ligne, pas encore envoyée.
     */
    #[AsTwigFunction('can_send_to_instagram')]
    public function canSendToInstagram(Post $post): bool
    {
        return $this->publishing->canSendExisting($post);
    }

    #[AsTwigFunction('instagram_crosspost')]
    public function instagramCrosspost(Post|Story $item): ?InstagramCrosspost
    {
        return $item instanceof Post ? $this->crossposts->forPost($item) : $this->crossposts->forStory($item);
    }
}
