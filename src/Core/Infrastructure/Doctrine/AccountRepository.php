<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Account>
 */
final class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function findLocal(string $username): ?Account
    {
        $account = $this->createQueryBuilder('a')
            ->where('a.username = :username')
            ->andWhere('a.domain IS NULL')
            ->setParameter('username', mb_strtolower($username))
            ->getQuery()
            ->getOneOrNullResult();
        \assert(null === $account || $account instanceof Account);

        return $account;
    }

    public function isLocalUsernameTaken(string $username): bool
    {
        return $this->findLocal($username) instanceof Account;
    }

    /**
     * Comptes locaux que le compte ne suit pas encore, les plus récents d'abord.
     *
     * @return list<Account>
     */
    public function suggestionsFor(Account $account, int $limit = 5): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.domain IS NULL')
            ->andWhere('a != :me')
            ->andWhere('a NOT IN (SELECT IDENTITY(f.following) FROM App\Core\Domain\Social\Follow f WHERE f.follower = :me)')
            ->setParameter('me', $account->getId(), UuidType::NAME)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
