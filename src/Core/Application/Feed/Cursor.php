<?php

declare(strict_types=1);

namespace App\Core\Application\Feed;

use App\Core\Domain\Post\Post;
use App\Core\Domain\PublicId;
use Symfony\Component\Uid\Uuid;

/**
 * Position dans un fil : date de publication et identifiant du dernier élément affiché.
 * Plus stable qu'un numéro de page quand de nouvelles publications arrivent.
 */
final readonly class Cursor
{
    public function __construct(
        public \DateTimeImmutable $publishedAt,
        public Uuid $id,
    ) {
    }

    public static function after(Post $post): self
    {
        return new self($post->getPublishedAt(), $post->getId());
    }

    public static function fromString(?string $value): ?self
    {
        if (null === $value || !preg_match('/^(\d{1,12})\.(\d{1,6})_([1-9A-HJ-NP-Za-km-z]{22})$/', $value, $m)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('U.u', $m[1].'.'.str_pad($m[2], 6, '0'));
        $id = PublicId::decode($m[3]);
        if (false === $date || !$id instanceof Uuid) {
            return null;
        }

        return new self($date, $id);
    }

    public function toString(): string
    {
        return $this->publishedAt->format('U.u').'_'.$this->id->toBase58();
    }
}
