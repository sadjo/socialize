<?php

declare(strict_types=1);

namespace App\Core\Domain\Social;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LikeRepository::class)]
#[ORM\Table(name: 'likes')]
#[ORM\UniqueConstraint(name: 'uniq_likes_account_post', columns: ['account_id', 'post_id'])]
class Like
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getPost(): Post
    {
        return $this->post;
    }
}
