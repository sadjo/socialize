<?php

declare(strict_types=1);

namespace App\Core\Domain\Post;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaStatus;
use App\Core\Domain\Music\MusicChoice;
use App\Core\Domain\Music\MusicTrack;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Infrastructure\Doctrine\PostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
#[ORM\Index(name: 'idx_posts_account_published', columns: ['account_id', 'published_at'])]
#[ORM\Index(name: 'idx_posts_visibility_published', columns: ['visibility', 'published_at'])]
class Post
{
    public const int MAX_MEDIA = 20;
    public const int MAX_CAPTION_LENGTH = 2200;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $originalCreatedAt = null;

    #[ORM\Column(length: 30, enumType: PostSource::class)]
    private PostSource $source = PostSource::Local;

    /** Identifiant ActivityPub d'une publication reçue du fédivers. */
    #[ORM\Column(length: 500, unique: true, nullable: true)]
    private ?string $apUri = null;

    /** Page HTML de la publication sur son instance d'origine. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    /**
     * Version de l'identifiant ActivityPub. Elle augmente quand la publication est retirée du fédivers
     * (passage en privé) : Mastodon refuse de recréer un objet supprimé avec le même identifiant.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $apRevision = 1;

    /** Date de la dernière modification par l'auteur (null si jamais modifiée). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** Date d'archivage : une publication archivée n'est visible que par son auteur. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /**
     * Annoncer la publication au fédivers quand elle est prête. Faux pour un import discret :
     * elle reste consultable par ActivityPub, mais n'est pas envoyée aux abonnés.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $announce = true;

    #[ORM\Column]
    private int $likesCount = 0;

    #[ORM\Column]
    private int $commentsCount = 0;

    /** @var Collection<int, MediaAttachment> */
    #[ORM\OneToMany(targetEntity: MediaAttachment::class, mappedBy: 'post', cascade: ['persist', 'remove'], fetch: 'EAGER', orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $media;

    /**
     * Groupes d'abonnés auxquels l'élément est réservé (visibilité « Groupes »).
     *
     * @var Collection<int, AudienceGroup>
     */
    #[ORM\ManyToMany(targetEntity: AudienceGroup::class)]
    #[ORM\JoinTable(name: 'post_audience_groups')]
    #[ORM\JoinColumn(name: 'post_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'group_id', onDelete: 'CASCADE')]
    private Collection $audienceGroups;

    /** Musique jouée quand la publication est à l'écran (morceau libre d'Openverse). */
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
        #[ORM\Column(type: Types::TEXT)]
        private string $caption = '',
        #[ORM\Column(length: 20, enumType: Visibility::class)]
        private Visibility $visibility = Visibility::Public,
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $contentWarning = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $location = null,
    ) {
        $this->id = Uuid::v7();
        $this->caption = trim($caption);
        $this->contentWarning = $this->nullIfBlank($contentWarning);
        $this->location = $this->nullIfBlank($location);
        $this->publishedAt = new \DateTimeImmutable();
        $this->media = new ArrayCollection();
        $this->audienceGroups = new ArrayCollection();
    }

    /**
     * Publication reçue d'une instance distante.
     */
    public static function federated(
        Account $author,
        string $caption,
        Visibility $visibility,
        ?string $contentWarning,
        string $apUri,
        ?string $url,
        \DateTimeImmutable $publishedAt,
    ): self {
        $post = new self($author, $caption, $visibility, $contentWarning);
        $post->apUri = $apUri;
        $post->url = $url;
        $post->publishedAt = $publishedAt;
        $post->source = PostSource::Federated;

        return $post;
    }

    /**
     * Publication importée (archive Instagram) : date d'origine conservée, publiée à cette date
     * ou maintenant, et annoncée au fédivers seulement si demandé.
     */
    public static function imported(
        Account $author,
        string $caption,
        Visibility $visibility,
        \DateTimeImmutable $originalCreatedAt,
        \DateTimeImmutable $publishedAt,
        bool $announce,
    ): self {
        $post = new self($author, mb_substr(trim($caption), 0, self::MAX_CAPTION_LENGTH), $visibility);
        $post->source = PostSource::InstagramImport;
        $post->originalCreatedAt = $originalCreatedAt;
        $post->publishedAt = $publishedAt;
        $post->announce = $announce;

        return $post;
    }

    public function isImported(): bool
    {
        return PostSource::InstagramImport === $this->source;
    }

    public function shouldAnnounce(): bool
    {
        return $this->announce;
    }

    /**
     * Publication importée discrètement, à annoncer finalement aux abonnés du fédivers.
     */
    public function allowAnnouncement(): void
    {
        $this->announce = true;
    }

    public function updateFederated(string $caption, ?string $contentWarning): void
    {
        $this->caption = trim($caption);
        $this->contentWarning = self::nullIfBlank($contentWarning);
    }

    public function getApUri(): ?string
    {
        return $this->apUri;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function isFederated(): bool
    {
        return null !== $this->apUri;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Identifiant court utilisé dans les URL (/p/…).
     */
    public function getPublicId(): string
    {
        return $this->id->toBase58();
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getCaption(): string
    {
        return $this->caption;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function isSensitive(): bool
    {
        return null !== $this->contentWarning;
    }

    public function getContentWarning(): ?string
    {
        return $this->contentWarning;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getOriginalCreatedAt(): ?\DateTimeImmutable
    {
        return $this->originalCreatedAt;
    }

    public function getSource(): PostSource
    {
        return $this->source;
    }

    public function getLikesCount(): int
    {
        return $this->likesCount;
    }

    public function getCommentsCount(): int
    {
        return $this->commentsCount;
    }

    /**
     * @return Collection<int, MediaAttachment>
     */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    public function getCover(): ?MediaAttachment
    {
        return $this->media->first() ?: null;
    }

    public function isProcessing(): bool
    {
        return $this->media->exists(static fn (int $i, MediaAttachment $m): bool => MediaStatus::Processing === $m->getStatus());
    }

    public function hasVideo(): bool
    {
        return $this->media->exists(static fn (int $i, MediaAttachment $m): bool => $m->isVideo());
    }

    /**
     * Modification par l'auteur : texte, lieu, visibilité et avertissement de contenu.
     */
    public function edit(string $caption, Visibility $visibility, ?string $contentWarning, ?string $location, \DateTimeImmutable $now): void
    {
        if ($this->isFederated()) {
            throw new \DomainException('Une publication reçue du fédivers ne se modifie pas ici.');
        }
        $this->caption = trim($caption);
        $this->visibility = $visibility;
        $this->contentWarning = self::nullIfBlank($contentWarning);
        $this->location = self::nullIfBlank($location);
        $this->updatedAt = $now;
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
        if (Visibility::Groups === $this->visibility && $this->audienceGroups->isEmpty()) {
            $this->visibility = Visibility::Private;
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

    /**
     * Choix (ou retrait, avec null) de la musique de la publication.
     */
    public function chooseMusic(?MusicChoice $choice): void
    {
        $this->music = $choice?->track;
        $this->musicStart = $choice->start ?? 0;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /**
     * Range la publication dans les archives de son auteur, sans changer sa visibilité.
     */
    public function archive(\DateTimeImmutable $now): void
    {
        if ($this->isFederated()) {
            throw new \DomainException('Une publication reçue du fédivers ne s’archive pas ici.');
        }
        $this->archivedAt ??= $now;
    }

    public function unarchive(): void
    {
        $this->archivedAt = null;
    }

    /**
     * La publication part-elle dans le fédivers ? (ni privée ni archivée, et rédigée ici).
     */
    public function isFederable(): bool
    {
        return $this->account->isLocal() && !$this->isArchived() && Visibility::Private !== $this->visibility;
    }

    public function getApRevision(): int
    {
        return $this->apRevision;
    }

    /**
     * La publication vient d'être retirée du fédivers : son prochain envoi aura un nouvel identifiant.
     */
    public function retireFromFediverse(): void
    {
        ++$this->apRevision;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function removeMedia(MediaAttachment $media): void
    {
        $this->media->removeElement($media);
    }

    public function addMedia(MediaAttachment $media): void
    {
        if ($this->media->count() >= self::MAX_MEDIA) {
            throw new \DomainException(\sprintf('Une publication contient au plus %d médias.', self::MAX_MEDIA));
        }
        $this->media->add($media);
    }

    public function isOwnedBy(?Account $account): bool
    {
        return $account instanceof Account && $account->getId()->equals($this->account->getId());
    }

    public function incrementLikes(): void
    {
        ++$this->likesCount;
    }

    public function decrementLikes(): void
    {
        $this->likesCount = max(0, $this->likesCount - 1);
    }

    public function incrementComments(): void
    {
        ++$this->commentsCount;
    }

    public function decrementComments(): void
    {
        $this->commentsCount = max(0, $this->commentsCount - 1);
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
