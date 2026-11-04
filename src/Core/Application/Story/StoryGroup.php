<?php

declare(strict_types=1);

namespace App\Core\Application\Story;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Story\Story;

/**
 * Stories d'un compte, telles qu'affichées dans la barre et la visionneuse.
 */
final readonly class StoryGroup
{
    /**
     * @param list<Story>         $stories du plus ancien au plus récent
     * @param array<string, true> $seen    identifiants des stories déjà vues
     */
    public function __construct(
        public Account $account,
        public array $stories,
        public array $seen = [],
    ) {
    }

    public function isFullySeen(): bool
    {
        return array_all($this->stories, fn (Story $story): bool => isset($this->seen[$story->getPublicId()]));
    }

    /**
     * Index de la première story pas encore vue (0 si tout est vu).
     */
    public function firstUnseenIndex(): int
    {
        foreach ($this->stories as $i => $story) {
            if (!isset($this->seen[$story->getPublicId()])) {
                return $i;
            }
        }

        return 0;
    }

    public function latest(): Story
    {
        $latest = array_last($this->stories);
        \assert(null !== $latest, 'Un groupe contient au moins une story.');

        return $latest;
    }
}
