<?php

declare(strict_types=1);

namespace App\Core\Domain\Story;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\HighlightRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Story à la une : un ensemble de stories gardées sur le profil, au-delà de 24 h.
 */
#[ORM\Entity(repositoryClass: HighlightRepository::class)]
#[ORM\Table(name: 'story_highlights')]
#[ORM\Index(name: 'idx_highlights_account_position', columns: ['account_id', 'position'])]
class Highlight
{
    public const int MAX_TITLE_LENGTH = 30;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Story> */
    #[ORM\OneToMany(targetEntity: Story::class, mappedBy: 'highlight')]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $stories;

    /** Image de couverture choisie (fichier des copies, carré) ; sans elle, la story la plus récente sert de couverture. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverPath = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(length: 30)]
        private string $title,
        #[ORM\Column]
        private int $position = 0,
    ) {
        $this->id = Uuid::v7();
        $this->rename($title);
        $this->createdAt = new \DateTimeImmutable();
        $this->stories = new ArrayCollection();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $title = trim($title);
        if ('' === $title) {
            throw new \DomainException('Donnez un titre à la story à la une.');
        }
        $this->title = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * @return Collection<int, Story>
     */
    public function getStories(): Collection
    {
        return $this->stories;
    }

    public function getCoverPath(): ?string
    {
        return $this->coverPath;
    }

    public function changeCover(?string $coverPath): void
    {
        $this->coverPath = $coverPath;
    }

    /**
     * Couverture par défaut (sans image choisie) : la story la plus récente qui a un média prêt.
     */
    public function getCover(): ?Story
    {
        foreach (array_reverse($this->stories->toArray()) as $story) {
            if ($story->getMedia()?->isReady()) {
                return $story;
            }
        }

        return null;
    }

    public function isOwnedBy(?Account $account): bool
    {
        return $account instanceof Account && $account->getId()->equals($this->account->getId());
    }
}
