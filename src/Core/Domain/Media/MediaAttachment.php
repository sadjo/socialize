<?php

declare(strict_types=1);

namespace App\Core\Domain\Media;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\MediaAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Photo ou vidéo d'une publication ou d'une story. L'original n'est jamais modifié ni servi publiquement ;
 * seules les copies (« dérivés ») le sont, sans données de localisation.
 */
#[ORM\Entity(repositoryClass: MediaAttachmentRepository::class)]
#[ORM\Table(name: 'media_attachments')]
class MediaAttachment
{
    public const string DERIVATIVE_LARGE = 'large';
    public const string DERIVATIVE_THUMB = 'thumb';
    public const string DERIVATIVE_VIDEO = 'video';
    public const string DERIVATIVE_POSTER = 'poster';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: MediaStatus::class)]
    private MediaStatus $status = MediaStatus::Processing;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(nullable: true)]
    private ?float $duration = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $blurhash = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $derivatives = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $exif = [];

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failureReason = null;

    /** Adresse du média sur son instance d'origine (publication reçue du fédivers). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remoteUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'media')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Post $post = null;

    #[ORM\OneToOne(inversedBy: 'media')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Story $story = null;

    public function __construct(
        Post|Story $owner,
        #[ORM\Column(length: 20, enumType: MediaType::class)]
        private MediaType $type,
        #[ORM\Column]
        private int $position,
        #[ORM\Column(length: 255)]
        private string $originalPath,
        #[ORM\Column(length: 100)]
        private string $mimeType,
        #[ORM\Column(type: Types::BIGINT)]
        private int|string $size,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $altText = null,
    ) {
        $this->id = Uuid::v7();
        $altText = null !== $altText ? trim($altText) : null;
        $this->altText = '' === $altText ? null : $altText;
        $this->createdAt = new \DateTimeImmutable();
        if ($owner instanceof Post) {
            $this->post = $owner;
            $owner->addMedia($this);
        } else {
            $this->story = $owner;
            $owner->attach($this);
        }
    }

    /**
     * Média d'une publication distante : il reste sur son instance d'origine.
     */
    public static function remote(Post $post, MediaType $type, int $position, string $remoteUrl, string $mimeType, ?string $altText, ?int $width, ?int $height, ?string $blurhash): self
    {
        $media = new self($post, $type, $position, '', $mimeType, 0, $altText);
        $media->remoteUrl = $remoteUrl;
        $media->width = $width;
        $media->height = $height;
        $media->blurhash = $blurhash;
        $media->status = MediaStatus::Ready;

        return $media;
    }

    public function getRemoteUrl(): ?string
    {
        return $this->remoteUrl;
    }

    public function isRemote(): bool
    {
        return null !== $this->remoteUrl;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function getStory(): ?Story
    {
        return $this->story;
    }

    public function getType(): MediaType
    {
        return $this->type;
    }

    public function isVideo(): bool
    {
        return MediaType::Video === $this->type;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getStatus(): MediaStatus
    {
        return $this->status;
    }

    public function isReady(): bool
    {
        return MediaStatus::Ready === $this->status;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function getOriginalPath(): string
    {
        return $this->originalPath;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getDuration(): ?float
    {
        return $this->duration;
    }

    public function getBlurhash(): ?string
    {
        return $this->blurhash;
    }

    /**
     * @return array<string, string>
     */
    public function getDerivatives(): array
    {
        return $this->derivatives;
    }

    public function getDerivative(string $name): ?string
    {
        return $this->derivatives[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getExif(): array
    {
        return $this->exif;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * @param array<string, string> $derivatives
     * @param array<string, string> $exif
     */
    public function markReady(int $width, int $height, array $derivatives, array $exif = [], ?string $blurhash = null, ?float $duration = null): void
    {
        $this->width = $width;
        $this->height = $height;
        $this->derivatives = $derivatives;
        $this->exif = $exif;
        $this->blurhash = $blurhash;
        $this->duration = $duration;
        $this->status = MediaStatus::Ready;
        $this->failureReason = null;
    }

    public function markFailed(string $reason): void
    {
        $this->status = MediaStatus::Failed;
        $this->failureReason = mb_substr($reason, 0, 500);
    }
}
