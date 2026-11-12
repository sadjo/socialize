<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Crosspost\Meta\Domain\InstagramConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstagramConnection>
 */
final class InstagramConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstagramConnection::class);
    }

    public function forAccount(?Account $account): ?InstagramConnection
    {
        return $account instanceof Account ? $this->findOneBy(['account' => $account]) : null;
    }

    /**
     * Connexions dont le jeton n'a pas été renouvelé depuis $before et n'est pas connu comme refusé.
     *
     * @return list<InstagramConnection>
     */
    public function toRefresh(\DateTimeImmutable $before): array
    {
        /** @var list<InstagramConnection> $connections */
        $connections = $this->createQueryBuilder('c')
            ->where('c.refreshedAt < :before')
            ->andWhere('c.expiredAt IS NULL')
            ->setParameter('before', $before)
            ->getQuery()->getResult();

        return $connections;
    }
}
