<?php

declare(strict_types=1);

namespace App\Core\Domain\Story;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Music\MusicChoice;
use App\Core\Domain\Music\MusicTrack;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Photo ou vidéo visible 24 h. Une story expirée n'est pas supprimée : elle rejoint
 * les archives de son compte et peut être mise à la une.
 */
#[ORM\Entity(repositoryClass: StoryRepository::class)]
#[ORM\Table(name: 'stories')]
#[ORM\Index(name: 'idx_stories_account_expires', columns: ['account_id', 'expires_at'])]
class Story
{
    public const string LIFETIME = '+24 hours';
    public const int MAX_TEXT_LENGTH = 200;
    public const int MAX_PER_UPLOAD = 10;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\OneToOne(targetEntity: MediaAttachment::class, mappedBy: 'story', cascade: ['persist', 'remove'])]
    private ?MediaAttachment $media = null;

    #[ORM\ManyToOne(inversedBy: 'stories')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Highlight $highlight = null;

    #[ORM\Column]
    private int $viewsCount = 0;

    /**
     * Groupes d'abonnés auxquels l'élément est réservé (visibilité « Groupes »).
     *
     * @var Collection<int, AudienceGroup>
     */
    #[ORM\ManyToMany(targetEntity: AudienceGroup::class)]
    #[ORM\JoinTable(name: 'story_audience_groups')]
    #[ORM\JoinColumn(name: 'story_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'group_id', onDelete: 'CASCADE')]
    private Collection $audienceGroups;

    /** Musique jouée pendant la story (morceau libre d'Openverse). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MusicTrack $music = null;

    /** Début de l'extrait joué, en secondes. */
    #[ORM\Column(options: ['default' => 0])]
    private int $musicStart = 0;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 200, nullable: true)]
        private ?string $text = null,
        #[ORM\Column(length: 20, enumType: Visibility::class)]
        private Visibility $audience = Visibility::Public,
    ) {
        if (!\in_array($audience, [Visibility::Public, Visibility::Followers, Visibility::Groups], true)) {
            throw new \DomainException('Une story est visible par tout le monde, par les abonnés ou par des groupes d’abonnés.');
        }
        $this->id = Uuid::v7();
        $this->audienceGroups = new ArrayCollection();
        $text = null !== $text ? trim($text) : null;
        $this->text = null === $text || '' === $text ? null : mb_substr($text, 0, self::MAX_TEXT_LENGTH);
        $this->expiresAt = $this->createdAt->modify(self::LIFETIME);
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

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getAudience(): Visibility
    {
        return $this->audience;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt > $now;
    }

    public function getMedia(): ?MediaAttachment
    {
        return $this->media;
    }

    public function attach(MediaAttachment $media): void
    {
        if ($this->media instanceof MediaAttachment) {
            throw new \DomainException('Une story contient un seul média.');
        }
        $this->media = $media;
    }

    /**
     * @return list<AudienceGroup>
     */
    public function getAudienceGroups(): array
    {
        $groups = $this->audienceGroups->toArray();
        usort($groups, static fn (AudienceGroup $a, AudienceGroup $b): int => strcasecmp($a->getName(), $b->getName()));

        return $groups;
    }

    /**
     * Groupes auxquels l'élément est réservé. Visibilité « Groupes » sans aucun groupe : il devient privé
     * (jamais plus visible que voulu).
     *
     * @param list<AudienceGroup> $groups
     */
    public function restrictTo(array $groups): void
    {
        $this->audienceGroups->clear();
        foreach ($groups as $group) {
            if ($group->isOwnedBy($this->account) && !$this->audienceGroups->contains($group)) {
                $this->audienceGroups->add($group);
            }
        }
        if (Visibility::Groups === $this->audience && $this->audienceGroups->isEmpty()) {
            $this->audience = Visibility::Private;
        }
    }

    /**
     * Le compte est-il membre d'un des groupes auxquels l'élément est réservé ?
     */
    public function isInAudienceGroups(?Account $account): bool
    {
        return $account instanceof Account && $this->audienceGroups->exists(static fn (int $i, AudienceGroup $g): bool => $g->hasMember($account));
    }

    /**
     * Membres de ses groupes, sans doublon.
     *
     * @return list<Account>
     */
    public function getAudienceMembers(): array
    {
        $members = [];
        foreach ($this->audienceGroups as $group) {
            foreach ($group->getMembers() as $member) {
                $members[$member->getId()->toRfc4122()] = $member;
            }
        }

        return array_values($members);
    }

    public function getMusic(): ?MusicTrack
    {
        return $this->music;
    }

    public function getMusicStart(): int
    {
        return $this->musicStart;
    }

    public function chooseMusic(?MusicChoice $choice): void
    {
        $this->music = $choice?->track;
        $this->musicStart = $choice->start ?? 0;
    }

    public function getHighlight(): ?Highlight
    {
        return $this->highlight;
    }

    public function pinTo(?Highlight $highlight): void
    {
        $this->highlight = $highlight;
    }

    public function getViewsCount(): int
    {
        return $this->viewsCount;
    }

    public function incrementViews(): void
    {
        ++$this->viewsCount;
    }

    public function isOwnedBy(?Account $account): bool
    {
        return $account instanceof Account && $account->getId()->equals($this->account->getId());
    }
}
