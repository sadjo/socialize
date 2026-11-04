<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\PublicId;
use App\Core\Domain\Story\Highlight;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Highlight>
 */
final class HighlightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Highlight::class);
    }

    public function findByPublicId(string $publicId): ?Highlight
    {
        $id = PublicId::decode($publicId);

        return $id instanceof \Symfony\Component\Uid\Uuid ? $this->find($id) : null;
    }

    /**
     * @return list<Highlight>
     */
    public function forAccount(Account $account): array
    {
        return $this->findBy(['account' => $account], ['position' => 'ASC', 'createdAt' => 'ASC']);
    }

    public function nextPosition(Account $account): int
    {
        return \count($this->forAccount($account));
    }
}
