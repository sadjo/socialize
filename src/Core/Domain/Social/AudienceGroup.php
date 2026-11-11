<?php

declare(strict_types=1);

namespace App\Core\Domain\Social;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AudienceGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Groupe d'abonnés d'un compte (Famille, Amis…) : une publication ou une story peut lui être réservée.
 * Seuls les membres encore abonnés y ont accès.
 */
#[ORM\Entity(repositoryClass: AudienceGroupRepository::class)]
#[ORM\Table(name: 'audience_groups')]
#[ORM\Index(name: 'idx_audience_groups_owner', columns: ['owner_id'])]
class AudienceGroup
{
    public const int MAX_NAME_LENGTH = 50;
    public const int MAX_PER_ACCOUNT = 50;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Account> */
    #[ORM\ManyToMany(targetEntity: Account::class)]
    #[ORM\JoinTable(name: 'audience_group_members')]
    #[ORM\JoinColumn(name: 'group_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'account_id', onDelete: 'CASCADE')]
    private Collection $members;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $owner,
        #[ORM\Column(length: 50)]
        private string $name,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->members = new ArrayCollection();
        $this->rename($name);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->id->toBase58();
    }

    public function getOwner(): Account
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \DomainException('Donnez un nom au groupe.');
        }
        $this->name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    public function isOwnedBy(?Account $account): bool
    {
        return $account instanceof Account && $account->getId()->equals($this->owner->getId());
    }

    /**
     * @return list<Account>
     */
    public function getMembers(): array
    {
        $members = $this->members->toArray();
        usort($members, static fn (Account $a, Account $b): int => strcasecmp($a->getAcct(), $b->getAcct()));

        return $members;
    }

    public function hasMember(Account $account): bool
    {
        return $this->members->exists(static fn (int $i, Account $m): bool => $m->getId()->equals($account->getId()));
    }

    /**
     * @param list<Account> $members
     */
    public function replaceMembers(array $members): void
    {
        $this->members->clear();
        foreach ($members as $member) {
            if (!$member->getId()->equals($this->owner->getId()) && !$this->hasMember($member)) {
                $this->members->add($member);
            }
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
