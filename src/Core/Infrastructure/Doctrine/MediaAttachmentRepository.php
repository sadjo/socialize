<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Media\MediaAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaAttachment>
 */
final class MediaAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaAttachment::class);
    }

    /**
     * Progression de la conversion d'une vidéo, enregistrée directement (sans toucher au reste de l'unité de travail) ;
     * null l'efface (conversion terminée).
     */
    public function saveProgress(MediaAttachment $media, ?int $percent): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->update(MediaAttachment::class, 'm')
            ->set('m.progress', ':progress')
            ->where('m.id = :id')
            ->setParameter('progress', null === $percent ? null : max(0, min(99, $percent)))
            ->setParameter('id', $media->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->getQuery()
            ->execute();
    }
}
