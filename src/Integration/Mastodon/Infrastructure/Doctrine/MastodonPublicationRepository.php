<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Infrastructure\Doctrine;

use App\Integration\Mastodon\Domain\MastodonPublication;
use App\Integration\Mastodon\Domain\MastodonPublicationState;
use App\Integration\Mastodon\Domain\MastodonUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<MastodonPublication>
 */
final class MastodonPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MastodonPublication::class);
    }

    /**
     * Envois en cours, et échecs récents, à montrer à leur auteur.
     *
     * @return list<MastodonPublication>
     */
    public function unfinishedOf(MastodonUser $user, \DateTimeImmutable $since): array
    {
        /** @var list<MastodonPublication> $publications */
        $publications = $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')->setParameter('user', $user->getId(), UuidType::NAME)
            ->andWhere('p.state = :pending OR (p.state = :failed AND p.createdAt >= :since)')
            ->setParameter('pending', MastodonPublicationState::Pending->value)
            ->setParameter('failed', MastodonPublicationState::Failed->value)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->orderBy('p.createdAt', \SortDirection::Descending)
            ->setMaxResults(10)
            ->getQuery()->getResult();

        return $publications;
    }
}
