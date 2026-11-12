<?php

declare(strict_types=1);

namespace App\Integration\SynologyPhotos\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Integration\SynologyPhotos\Domain\SynologyPhotosConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynologyPhotosConnection>
 */
final class SynologyPhotosConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynologyPhotosConnection::class);
    }

    public function forAccount(?Account $account): ?SynologyPhotosConnection
    {
        return $account instanceof Account ? $this->findOneBy(['account' => $account]) : null;
    }
}
