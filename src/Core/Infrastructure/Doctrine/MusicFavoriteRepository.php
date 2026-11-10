<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Music\MusicFavorite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<MusicFavorite>
 */
final class MusicFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicFavorite::class);
    }

    public function findOne(Account $account, string $openverseId): ?MusicFavorite
    {
        return $this->findOneBy(['account' => $account, 'openverseId' => strtolower($openverseId)]);
    }

    /**
     * Favoris du compte, du plus récent au plus ancien.
     *
     * @return list<MusicFavorite>
     */
    public function forAccount(Account $account): array
    {
        /* @var list<MusicFavorite> */
        return $this->findBy(['account' => $account], ['createdAt' => 'DESC'], MusicFavorite::MAX_PER_ACCOUNT);
    }

    public function countFor(Account $account): int
    {
        return $this->count(['account' => $account]);
    }

    /**
     * Parmi ces morceaux, ceux qui sont dans les favoris du compte.
     *
     * @param list<string> $openverseIds
     *
     * @return array<string, true>
     */
    public function idsAmong(Account $account, array $openverseIds): array
    {
        if ([] === $openverseIds) {
            return [];
        }
        /** @var list<array{openverseId: string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.openverseId')
            ->where('f.account = :account')
            ->andWhere('f.openverseId IN (:ids)')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('ids', array_map(strtolower(...), $openverseIds))
            ->getQuery()
            ->getArrayResult();

        return array_fill_keys(array_column($rows, 'openverseId'), true);
    }
}
