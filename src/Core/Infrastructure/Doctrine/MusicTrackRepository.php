<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Music\MusicTrack;
use App\Core\Domain\PublicId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MusicTrack>
 */
final class MusicTrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicTrack::class);
    }

    public function findByPublicId(string $publicId): ?MusicTrack
    {
        $id = PublicId::decode($publicId);

        return $id instanceof Uuid ? $this->find($id) : null;
    }

    public function findByOpenverseId(string $openverseId): ?MusicTrack
    {
        return $this->findOneBy(['openverseId' => $openverseId]);
    }
}
