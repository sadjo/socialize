<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine;

use App\Core\Domain\Story\Highlight;
use App\Media\Application\HighlightCovers;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Story à la une supprimée (à la main, ou quand sa dernière story la quitte) : son image de couverture aussi.
 */
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: Highlight::class)]
final readonly class HighlightCoverCleaner
{
    public function __construct(
        private HighlightCovers $covers,
    ) {
    }

    public function postRemove(Highlight $highlight): void
    {
        $this->covers->delete($highlight->getCoverPath());
    }
}
