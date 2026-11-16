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
        private \App\Crosspost\Meta\Application\InstagramConnections $connections,
        private \App\Web\Security\CurrentAccount $currentAccount,
    ) {
    }

    /**
     * Bouton « Publier sur Instagram » d'une publication déjà en ligne, pas encore envoyée.
     */
    /**
     * Connexion Instagram du compte connecté (droits de suppression…), ou null.
     */
    #[AsTwigFunction('instagram_connection')]
    public function connection(): ?\App\Crosspost\Meta\Domain\InstagramConnection
    {
        return $this->connections->usable($this->currentAccount->get());
    }

    #[AsTwigFunction('can_relaunch_instagram')]
    public function canRelaunch(InstagramCrosspost $crosspost): bool
    {
        return $this->publishing->canRelaunch($crosspost);
    }

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
