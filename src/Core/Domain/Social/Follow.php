<?php

declare(strict_types=1);

namespace App\Core\Domain\Social;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FollowRepository::class)]
#[ORM\Table(name: 'follows')]
#[ORM\UniqueConstraint(name: 'uniq_follows_pair', columns: ['follower_id', 'following_id'])]
class Follow
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $follower,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $following,
        #[ORM\Column(length: 20, enumType: FollowState::class)]
        private FollowState $state = FollowState::Accepted,
    ) {
        if ($follower->getId()->equals($following->getId())) {
            throw new \DomainException('Un compte ne peut pas se suivre lui-même.');
        }
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getFollower(): Account
    {
        return $this->follower;
    }

    public function getFollowing(): Account
    {
        return $this->following;
    }

    public function getState(): FollowState
    {
        return $this->state;
    }

    public function accept(): void
    {
        $this->state = FollowState::Accepted;
    }
}
