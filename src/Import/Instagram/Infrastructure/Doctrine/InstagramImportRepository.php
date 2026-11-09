<?php

declare(strict_types=1);

namespace App\Import\Instagram\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\PublicId;
use App\Import\Instagram\Domain\ImportStatus;
use App\Import\Instagram\Domain\InstagramImport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstagramImport>
 */
final class InstagramImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstagramImport::class);
    }

    public function findByPublicId(string $publicId): ?InstagramImport
    {
        $id = PublicId::decode($publicId);

        return $id instanceof Uuid ? $this->find($id) : null;
    }

    /**
     * Import en cours d'un compte (un seul à la fois).
     */
    public function activeFor(Account $account): ?InstagramImport
    {
        $active = array_map(static fn (ImportStatus $s): string => $s->value, array_filter(ImportStatus::cases(), static fn (ImportStatus $s): bool => $s->isActive()));

        return $this->findOneBy(['account' => $account, 'status' => array_values($active)], ['createdAt' => 'DESC']);
    }

    /**
     * @return list<InstagramImport>
     */
    public function finishedFor(Account $account, int $limit = 10): array
    {
        return \array_slice(array_values(array_filter(
            $this->findBy(['account' => $account], ['createdAt' => 'DESC'], 50),
            static fn (InstagramImport $i): bool => !$i->getStatus()->isActive(),
        )), 0, $limit);
    }
}
