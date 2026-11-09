<?php

declare(strict_types=1);

namespace App\Import\Instagram\Domain;

use App\Core\Domain\Account\Account;
use App\Import\Instagram\Infrastructure\Doctrine\ImportItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un élément de l'archive (publication ou story), avec son JSON d'origine pour pouvoir le retraiter.
 */
#[ORM\Entity(repositoryClass: ImportItemRepository::class)]
#[ORM\Table(name: 'instagram_import_items')]
#[ORM\UniqueConstraint(name: 'uniq_instagram_items_import_fingerprint', columns: ['import_id', 'fingerprint'])]
#[ORM\Index(name: 'idx_instagram_items_account_fingerprint', columns: ['account_id', 'fingerprint'])]
#[ORM\Index(name: 'idx_instagram_items_import_status', columns: ['import_id', 'status', 'taken_at'])]
class ImportItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: ItemStatus::class)]
    private ItemStatus $status = ItemStatus::Pending;

    /** Choix fait pour cet élément ; null : celui de tout l'import. */
    #[ORM\Column(length: 20, nullable: true, enumType: Placement::class)]
    private ?Placement $choice = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $message = null;

    /** Publication ou story créée. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $targetId = null;

    /**
     * @param list<string>          $mediaUris chemins des médias dans l'archive, dans l'ordre du carrousel
     * @param array<string, string> $exif      métadonnées de l'archive (appareil, position…)
     * @param array<mixed>          $raw       JSON d'origine de l'élément
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private InstagramImport $import,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(length: 20, enumType: ItemKind::class)]
        private ItemKind $kind,
        #[ORM\Column(length: 64)]
        private string $fingerprint,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $takenAt,
        #[ORM\Column(type: Types::TEXT)]
        private string $caption,
        #[ORM\Column(type: Types::JSON)]
        private array $mediaUris,
        #[ORM\Column(type: Types::JSON)]
        private array $exif,
        #[ORM\Column(type: Types::JSON)]
        private array $raw,
    ) {
        $this->id = Uuid::v7();
        // Une publication archivée sur Instagram est proposée dans les archives.
        if (ItemKind::ArchivedPost === $kind) {
            $this->choice = Placement::Archive;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->id->toBase58();
    }

    public function getImport(): InstagramImport
    {
        return $this->import;
    }

    public function getKind(): ItemKind
    {
        return $this->kind;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getTakenAt(): \DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function getCaption(): string
    {
        return $this->caption;
    }

    /**
     * @return list<string>
     */
    public function getMediaUris(): array
    {
        return $this->mediaUris;
    }

    public function hasVideo(): bool
    {
        return array_any($this->mediaUris, static fn (string $uri): bool => (bool) preg_match('/\.(mp4|mov|webm)$/i', $uri));
    }

    /**
     * @return array<string, string>
     */
    public function getExif(): array
    {
        return $this->exif;
    }

    /**
     * @return array<mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    public function getStatus(): ItemStatus
    {
        return $this->status;
    }

    public function getChoice(): ?Placement
    {
        return $this->choice;
    }

    public function choose(?Placement $choice): void
    {
        if (ItemStatus::Pending !== $this->status) {
            return;
        }
        $this->choice = $choice;
    }

    /**
     * Choix effectif : celui de l'élément, sinon celui de l'import.
     */
    public function placement(): Placement
    {
        return $this->choice ?? $this->import->getPlacement();
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getTargetId(): ?Uuid
    {
        return $this->targetId;
    }

    public function imported(Uuid $targetId, ?string $message = null): void
    {
        $this->status = ItemStatus::Imported;
        $this->targetId = $targetId;
        $this->message = $message;
    }

    public function skip(ItemStatus $status, string $message): void
    {
        $this->status = $status;
        $this->message = mb_substr($message, 0, 500);
    }
}
