<?php

declare(strict_types=1);

namespace App\Integration\Immich\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Integration\Immich\Domain\ImmichConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImmichConnection>
 */
final class ImmichConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImmichConnection::class);
    }

    public function forAccount(?Account $account): ?ImmichConnection
    {
        return $account instanceof Account ? $this->findOneBy(['account' => $account]) : null;
    }
}
