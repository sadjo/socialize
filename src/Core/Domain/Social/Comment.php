<?php

declare(strict_types=1);

namespace App\Core\Domain\Social;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
#[ORM\Index(name: 'idx_comments_post_created', columns: ['post_id', 'created_at'])]
class Comment
{
    public const int MAX_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 500, unique: true, nullable: true)]
    private ?string $apUri = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(type: Types::TEXT)]
        private string $content,
    ) {
        $this->id = Uuid::v7();
        $this->content = trim($content);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function federated(Post $post, Account $account, string $content, string $apUri, \DateTimeImmutable $createdAt): self
    {
        $comment = new self($post, $account, $content);
        $comment->apUri = $apUri;
        $comment->createdAt = $createdAt;

        return $comment;
    }

    /**
     * Un commentaire peut être supprimé par son auteur ou par l'auteur de la publication.
     */
    public function isDeletableBy(?Account $account): bool
    {
        return $account instanceof Account && ($account->getId()->equals($this->account->getId()) || $this->post->isOwnedBy($account));
    }

    public function getApUri(): ?string
    {
        return $this->apUri;
    }

    public function getPublicId(): string
    {
        return $this->id->toBase58();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
