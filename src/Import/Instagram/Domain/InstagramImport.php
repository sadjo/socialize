<?php

declare(strict_types=1);

namespace App\Import\Instagram\Domain;

use App\Core\Domain\Account\Account;
use App\Import\Instagram\Infrastructure\Doctrine\InstagramImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Import d'une archive Instagram : envoi (une ou plusieurs parties), analyse, choix des options, import.
 */
#[ORM\Entity(repositoryClass: InstagramImportRepository::class)]
#[ORM\Table(name: 'instagram_imports')]
#[ORM\Index(name: 'idx_instagram_imports_account', columns: ['account_id', 'created_at'])]
class InstagramImport
{
    public const int MAX_PARTS = 10;
    private const int MAX_LOG = 100;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: ImportStatus::class)]
    private ImportStatus $status = ImportStatus::Uploading;

    /**
     * Parties de l'archive. « owned » : fichier reçu par l'envoi, supprimé à la fin de l'import.
     *
     * @var list<array{name: string, size: int, received: int, path: string, owned: bool}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $parts = [];

    #[ORM\Column(length: 20, enumType: Placement::class)]
    private Placement $placement = Placement::PublicQuiet;

    /**
     * Groupe d'abonnés par défaut des publications importées « Abonnés seulement » (identifiant public), ou null :
     * tous les abonnés. Un groupe supprimé avant l'import rend ces publications privées.
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $defaultGroup = null;

    #[ORM\Column]
    private bool $keepOriginalDates = true;

    #[ORM\Column]
    private bool $importStories = true;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $counts = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $warnings = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $log = [];

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->id->toBase58();
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function isOwnedBy(?Account $account): bool
    {
        return $account instanceof Account && $account->getId()->equals($this->account->getId());
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function addPart(string $name, int $size, string $path, bool $owned): void
    {
        if (ImportStatus::Uploading !== $this->status || \count($this->parts) >= self::MAX_PARTS) {
            throw new \DomainException(\sprintf('Une archive compte au plus %d parties.', self::MAX_PARTS));
        }
        $this->parts[] = ['name' => $name, 'size' => $size, 'received' => $owned ? 0 : $size, 'path' => $path, 'owned' => $owned];
    }

    /**
     * @return list<array{name: string, size: int, received: int, path: string, owned: bool}>
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    public function receive(int $index, int $bytes): void
    {
        if (!isset($this->parts[$index])) {
            throw new \DomainException('Partie inconnue.');
        }
        $this->parts[$index]['received'] = min($this->parts[$index]['size'], $this->parts[$index]['received'] + $bytes);
    }

    public function getSize(): int
    {
        return array_sum(array_column($this->parts, 'size'));
    }

    public function getReceived(): int
    {
        return array_sum(array_column($this->parts, 'received'));
    }

    public function isUploaded(): bool
    {
        return [] !== $this->parts && $this->getReceived() >= $this->getSize();
    }

    public function startAnalysis(): void
    {
        $this->status = ImportStatus::Analyzing;
    }

    /**
     * @param array<string, int> $counts
     * @param list<string>       $warnings
     */
    public function analyzed(array $counts, array $warnings): void
    {
        $this->counts = $counts;
        $this->warnings = $warnings;
        $this->status = ImportStatus::Ready;
    }

    public function start(Placement $placement, bool $keepOriginalDates, bool $importStories, ?string $defaultGroup = null): void
    {
        if (ImportStatus::Ready !== $this->status) {
            throw new \DomainException('L’import a déjà été lancé.');
        }
        if (!\in_array($placement, Placement::forWholeImport(), true)) {
            throw new \DomainException('Choisissez une visibilité pour l’import.');
        }
        $this->placement = $placement;
        $this->defaultGroup = null !== $defaultGroup && '' !== $defaultGroup ? $defaultGroup : null;
        $this->keepOriginalDates = $keepOriginalDates;
        $this->importStories = $importStories;
        $this->status = ImportStatus::Importing;
    }

    /**
     * @param array<string, int> $results éléments importés, ignorés, déjà présents, en échec
     */
    public function complete(\DateTimeImmutable $now, array $results = []): void
    {
        $this->status = ImportStatus::Completed;
        $this->finishedAt = $now;
        $this->counts = array_merge($this->counts, $results);
    }

    public function fail(string $error, \DateTimeImmutable $now): void
    {
        $this->status = ImportStatus::Failed;
        $this->error = mb_substr($error, 0, 1000);
        $this->finishedAt = $now;
    }

    public function addLog(string $line): void
    {
        $this->log[] = $line;
        $this->log = \array_slice($this->log, -self::MAX_LOG);
    }

    /**
     * @return list<string>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    public function getPlacement(): Placement
    {
        return $this->placement;
    }

    public function getDefaultGroup(): ?string
    {
        return $this->defaultGroup;
    }

    public function keepsOriginalDates(): bool
    {
        return $this->keepOriginalDates;
    }

    public function importsStories(): bool
    {
        return $this->importStories;
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->counts;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
