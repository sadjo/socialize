<?php

declare(strict_types=1);

namespace App\Import\Instagram\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaStatus;
use App\Core\Domain\Media\MediaType;
use App\Core\Domain\PublicId;
use App\Import\Instagram\Domain\ImportItem;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Domain\ItemKind;
use App\Import\Instagram\Domain\ItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ImportItem>
 */
final class ImportItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportItem::class);
    }

    public function findInImport(InstagramImport $import, string $publicId): ?ImportItem
    {
        $id = PublicId::decode($publicId);

        return $id instanceof Uuid ? $this->findOneBy(['id' => $id, 'import' => $import]) : null;
    }

    /**
     * Un élément identique a-t-il déjà été importé par ce compte (relance d'un import, archive plus récente) ?
     */
    public function alreadyImported(Account $account, string $fingerprint): bool
    {
        return null !== $this->findOneBy(['account' => $account, 'fingerprint' => $fingerprint, 'status' => ItemStatus::Imported]);
    }

    /**
     * Prochains éléments à importer, du plus ancien au plus récent.
     *
     * @return list<ImportItem>
     */
    public function nextPending(InstagramImport $import, int $limit): array
    {
        return $this->findBy(['import' => $import, 'status' => ItemStatus::Pending], ['takenAt' => 'ASC', 'id' => 'ASC'], $limit);
    }

    /**
     * Publications de l'import, des plus récentes aux plus anciennes (choix publication par publication).
     *
     * @return list<ImportItem>
     */
    public function posts(InstagramImport $import): array
    {
        return $this->findBy(['import' => $import, 'kind' => [ItemKind::Post, ItemKind::ArchivedPost]], ['takenAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Tous les éléments de l'import, des plus récents aux plus anciens (détail d'un import terminé).
     *
     * @return list<ImportItem>
     */
    public function allOf(InstagramImport $import): array
    {
        return $this->findBy(['import' => $import], ['takenAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Vidéos de l'import (publications et stories créées) : combien au total, combien encore en transcodage.
     *
     * @return array{total: int, processing: int}
     */
    public function videoProgress(InstagramImport $import): array
    {
        $targets = fn (string $alias): string => $this->getEntityManager()->createQueryBuilder()
            ->select($alias.'.targetId')
            ->from(ImportItem::class, $alias)
            ->where($alias.'.import = :import')
            ->andWhere($alias.'.targetId IS NOT NULL')
            ->getDQL();

        /** @var array{total: int|string, processing: int|string|null} $row */
        $row = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(m.id) AS total', 'SUM(CASE WHEN m.status = :processing THEN 1 ELSE 0 END) AS processing')
            ->from(MediaAttachment::class, 'm')
            ->where('m.type = :video')
            ->andWhere(\sprintf('IDENTITY(m.post) IN (%s) OR IDENTITY(m.story) IN (%s)', $targets('post_item'), $targets('story_item')))
            ->setParameter('processing', MediaStatus::Processing)
            ->setParameter('video', MediaType::Video)
            ->setParameter('import', $import->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleResult();

        return ['total' => (int) $row['total'], 'processing' => (int) $row['processing']];
    }

    /**
     * Vidéos de l'import encore à convertir.
     *
     * @return list<string> identifiants (RFC 4122) des médias
     */
    public function pendingVideoIds(InstagramImport $import): array
    {
        $targets = fn (string $alias): string => $this->getEntityManager()->createQueryBuilder()
            ->select($alias.'.targetId')
            ->from(ImportItem::class, $alias)
            ->where($alias.'.import = :import')
            ->andWhere($alias.'.targetId IS NOT NULL')
            ->getDQL();

        /** @var list<MediaAttachment> $media */
        $media = $this->getEntityManager()->createQueryBuilder()
            ->select('m')
            ->from(MediaAttachment::class, 'm')
            ->where('m.type = :video')
            ->andWhere('m.status = :processing')
            ->andWhere(\sprintf('IDENTITY(m.post) IN (%s) OR IDENTITY(m.story) IN (%s)', $targets('post_item'), $targets('story_item')))
            ->setParameter('video', MediaType::Video)
            ->setParameter('processing', MediaStatus::Processing)
            ->setParameter('import', $import->getId(), UuidType::NAME)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (MediaAttachment $m): string => $m->getId()->toRfc4122(), $media);
    }

    /**
     * @return array<string, int> nombre d'éléments par état
     */
    public function countByStatus(InstagramImport $import): array
    {
        $counts = array_fill_keys(array_map(static fn (ItemStatus $s): string => $s->value, ItemStatus::cases()), 0);
        /** @var list<array{status: ItemStatus, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, COUNT(i.id) AS n')
            ->where('i.import = :import')
            ->setParameter('import', $import->getId(), UuidType::NAME)
            ->groupBy('i.status')
            ->getQuery()
            ->getResult();
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['n'];
        }

        return $counts;
    }
}
