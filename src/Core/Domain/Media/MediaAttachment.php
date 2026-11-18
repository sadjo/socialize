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

    /** Conversion d'une vidéo : pourcentage fait (null : pas encore commencée). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $progress = null;

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
    public static function remote(Post $post, MediaType $type, int $position, string $remoteUrl, string $mimeType, ?string $altText, ?int $width, ?int $height, ?string $blurhash, ?string $previewUrl = null): self
    {
        $media = new self($post, $type, $position, '', $mimeType, 0, $altText);
        $media->remoteUrl = $remoteUrl;
        // Vignette fournie par l'instance d'origine (adresse absolue), quand elle en publie une.
        if (null !== $previewUrl) {
            $media->derivatives = [self::DERIVATIVE_POSTER => $previewUrl];
        }
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

    /**
     * Média distant dont les copies ont été fabriquées ici (cacheRemote()) : servies comme celles d'un média local.
     * Un dérivé local est un chemin relatif ; l'affiche fournie par l'instance d'origine, une adresse absolue.
     */
    public function hasLocalCopies(): bool
    {
        return $this->isRemote() && [] !== $this->getLocalDerivatives();
    }

    /**
     * Copies rangées sur cette instance (sans l'affiche distante d'un média reçu du fédivers).
     *
     * @return array<string, string>
     */
    public function getLocalDerivatives(): array
    {
        return array_filter($this->derivatives, static fn (string $path): bool => !str_contains($path, '://'));
    }

    /**
     * Copies locales d'un média distant, fabriquées depuis l'image (ou l'affiche d'une vidéo) téléchargée :
     * elles remplacent l'affiche distante. La vidéo elle-même reste servie depuis son instance d'origine.
     *
     * @param array<string, string> $derivatives
     */
    public function cacheRemote(?int $width, ?int $height, array $derivatives, ?string $blurhash): void
    {
        \assert($this->isRemote());
        $this->width = $width ?? $this->width;
        $this->height = $height ?? $this->height;
        $this->derivatives = $derivatives;
        $this->blurhash ??= $blurhash;
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

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function describe(?string $altText): void
    {
        $altText = null !== $altText ? trim($altText) : null;
        $this->altText = '' === $altText ? null : $altText;
    }

    public function getPublicId(): string
    {
        return $this->id->toBase58();
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

    /**
     * Complète l'EXIF de l'original (par exemple avec celui d'une archive Instagram, qui vide les fichiers).
     * Les valeurs déjà lues dans le fichier sont gardées.
     *
     * @param array<string, string> $exif
     */
    public function addExif(array $exif): void
    {
        $this->exif += $exif;
        ksort($this->exif);
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
        $this->progress = null;
    }

    /**
     * Ajoute des copies à un média déjà prêt (nouvelles tailles fabriquées après coup).
     *
     * @param array<string, string> $derivatives
     */
    public function addDerivatives(array $derivatives): void
    {
        $this->derivatives = $derivatives + $this->derivatives;
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function markFailed(string $reason): void
    {
        $this->status = MediaStatus::Failed;
        $this->failureReason = mb_substr($reason, 0, 500);
    }
}
