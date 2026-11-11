<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\PublicId;
use App\Core\Domain\Social\AudienceGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AudienceGroup>
 */
final class AudienceGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AudienceGroup::class);
    }

    /**
     * Groupes d'un compte, par ordre alphabétique.
     *
     * @return list<AudienceGroup>
     */
    public function ownedBy(Account $owner): array
    {
        /* @var list<AudienceGroup> */
        return $this->findBy(['owner' => $owner], ['name' => 'ASC'], AudienceGroup::MAX_PER_ACCOUNT);
    }

    public function findOwned(Account $owner, string $publicId): ?AudienceGroup
    {
        $id = PublicId::decode($publicId);
        $group = $id instanceof Uuid ? $this->find($id) : null;

        return $group instanceof AudienceGroup && $group->isOwnedBy($owner) ? $group : null;
    }

    /**
     * Groupes du compte désignés par leurs identifiants publics (ceux d'un autre compte sont ignorés).
     *
     * @param list<string> $publicIds
     *
     * @return list<AudienceGroup>
     */
    public function ownedAmong(Account $owner, array $publicIds): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?AudienceGroup => $this->findOwned($owner, $id), array_unique($publicIds))));
    }
}
