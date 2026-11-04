<?php

declare(strict_types=1);

namespace App\Core\Domain\Story;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\StoryViewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une story vue par un compte : sert au cercle « déjà vu » et au compteur de vues.
 */
#[ORM\Entity(repositoryClass: StoryViewRepository::class)]
#[ORM\Table(name: 'story_views')]
#[ORM\UniqueConstraint(name: 'uniq_story_views_pair', columns: ['story_id', 'account_id'])]
class StoryView
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Story $story,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getStory(): Story
    {
        return $this->story;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }
}
