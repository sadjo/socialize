<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\Invitation;
use App\Core\Domain\PublicId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
final class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findByToken(string $token): ?Invitation
    {
        return '' === $token ? null : $this->findOneBy(['tokenHash' => Invitation::hash($token)]);
    }

    public function findByPublicId(string $publicId): ?Invitation
    {
        $id = PublicId::decode($publicId);

        return $id instanceof Uuid ? $this->find($id) : null;
    }

    /**
     * @return list<Invitation> les plus récentes d'abord
     */
    public function recent(int $limit = 100): array
    {
        /* @var list<Invitation> */
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }
}
