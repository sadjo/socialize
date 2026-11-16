<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Domain;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Story\Story;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramCrosspostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Envoi d'une publication ou d'une story vers Instagram, en plusieurs étapes : conteneurs créés (Instagram télécharge
 * les médias), attente qu'ils soient prêts, carrousel éventuel, puis publication.
 */
#[ORM\Entity(repositoryClass: InstagramCrosspostRepository::class)]
#[ORM\Table(name: 'instagram_crossposts')]
#[ORM\Index(name: 'idx_instagram_crossposts_status', columns: ['status'])]
class InstagramCrosspost
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: CrosspostStatus::class)]
    private CrosspostStatus $status = CrosspostStatus::Pending;

    /** @var list<string> conteneurs des médias (un seul, ou les éléments d'un carrousel) */
    #[ORM\Column(type: Types::JSON)]
    private array $mediaContainers = [];

    /** Conteneur à publier (le média seul, ou le carrousel une fois ses éléments prêts). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $containerId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $instagramMediaId = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $permalink = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $error = null;

    /** Vérifications de l'état des conteneurs. */
    #[ORM\Column]
    private int $checks = 0;

    /** @var list<string> copies JPEG temporaires (espace public), supprimées à la fin */
    #[ORM\Column(type: Types::JSON)]
    private array $files = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Post $post,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Story $story,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->updatedAt = $createdAt;
    }

    public static function forPost(Post $post, \DateTimeImmutable $now): self
    {
        return new self($post->getAccount(), $post, null, $now);
    }

    public static function forStory(Story $story, \DateTimeImmutable $now): self
    {
        return new self($story->getAccount(), null, $story, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function getStory(): ?Story
    {
        return $this->story;
    }

    public function getStatus(): CrosspostStatus
    {
        return $this->status;
    }

    public function isDone(): bool
    {
        return \in_array($this->status, [CrosspostStatus::Published, CrosspostStatus::Failed], true);
    }

    /**
     * Identifiant de la publication Instagram (gardé pour la retrouver et la supprimer).
     */
    public function getInstagramMediaId(): ?string
    {
        return $this->instagramMediaId;
    }

    /**
     * Publiée, mais Instagram n'a pas encore donné son lien : il est redemandé un peu plus tard.
     */
    public function awaitsPermalink(): bool
    {
        return CrosspostStatus::Published === $this->status && null === $this->permalink && null !== $this->instagramMediaId;
    }

    public function permalinkFound(?string $permalink, \DateTimeImmutable $now): void
    {
        $this->permalink = $permalink;
        ++$this->checks;
        $this->updatedAt = $now;
    }

    /**
     * Envoi en cours depuis longtemps sans aboutir (message perdu, worker arrêté) : il peut être relancé.
     */
    public function isStuck(\DateTimeImmutable $now): bool
    {
        return !$this->isDone() && $this->updatedAt < $now->modify('-15 minutes');
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return list<string>
     */
    public function getMediaContainers(): array
    {
        return $this->mediaContainers;
    }

    public function getContainerId(): ?string
    {
        return $this->containerId;
    }

    public function getPermalink(): ?string
    {
        return $this->permalink;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getChecks(): int
    {
        return $this->checks;
    }

    /**
     * @return list<string>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * @param list<string> $mediaContainers
     * @param list<string> $files
     */
    public function sent(array $mediaContainers, ?string $containerId, array $files, \DateTimeImmutable $now): void
    {
        $this->status = CrosspostStatus::Processing;
        $this->mediaContainers = $mediaContainers;
        $this->containerId = $containerId;
        $this->files = $files;
        $this->updatedAt = $now;
    }

    public function carouselCreated(string $containerId, \DateTimeImmutable $now): void
    {
        $this->containerId = $containerId;
        $this->updatedAt = $now;
    }

    public function checked(\DateTimeImmutable $now): void
    {
        ++$this->checks;
        $this->updatedAt = $now;
    }

    public function published(string $mediaId, ?string $permalink, \DateTimeImmutable $now): void
    {
        $this->checks = 0;
        $this->status = CrosspostStatus::Published;
        $this->instagramMediaId = $mediaId;
        $this->permalink = $permalink;
        $this->error = null;
        $this->files = [];
        $this->publishedAt = $now;
        $this->updatedAt = $now;
    }

    public function fail(string $error, \DateTimeImmutable $now): void
    {
        $this->status = CrosspostStatus::Failed;
        $this->error = mb_substr($error, 0, 1000);
        $this->files = [];
        $this->updatedAt = $now;
    }

    /**
     * Relance (après un échec, un envoi bloqué ou pour republier) : tout recommence. Rend l'identifiant de la
     * publication Instagram précédente, s'il y en a une.
     */
    public function retry(\DateTimeImmutable $now): ?string
    {
        $previous = $this->instagramMediaId;
        $this->status = CrosspostStatus::Pending;
        $this->mediaContainers = [];
        $this->containerId = null;
        $this->instagramMediaId = null;
        $this->permalink = null;
        $this->publishedAt = null;
        $this->error = null;
        $this->checks = 0;
        $this->updatedAt = $now;

        return $previous;
    }
}
