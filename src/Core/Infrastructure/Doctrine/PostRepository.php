<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Application\Feed\Cursor;
use App\Core\Application\Feed\TimelinePage;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\PostSource;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\PublicId;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Post>
 */
final class PostRepository extends ServiceEntityRepository
{
    /** Le visiteur (:viewer) est membre d'un des groupes de la publication p. */
    private const string MEMBER_OF_GROUPS = 'EXISTS (SELECT g.id FROM '.AudienceGroup::class.' g JOIN g.members gm WHERE g MEMBER OF p.audienceGroups AND gm.id = :viewer)';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * Publications rédigées par le compte, de la plus ancienne à la plus récente (depuis une date, si donnée), pour
     * les renvoyer dans l'ordre au fédivers (commande app:federation:reorder).
     *
     * @return list<Post>
     */
    public function chronologicalOf(Account $account, ?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.account = :account')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->orderBy('p.publishedAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending);
        if ($since instanceof \DateTimeImmutable) {
            $qb->andWhere('p.publishedAt >= :since')->setParameter('since', $since);
        }
        /** @var list<Post> $posts */
        $posts = $qb->getQuery()->getResult();

        return $posts;
    }

    public function findByPublicId(string $publicId): ?Post
    {
        $id = PublicId::decode($publicId);

        return $id instanceof Uuid ? $this->find($id) : null;
    }

    /**
     * Fil « Abonnements » : ses propres publications et celles des comptes suivis.
     */
    public function homeTimeline(Account $viewer, ?Cursor $cursor, int $limit): TimelinePage
    {
        // Auteurs lus d'abord, puis passés en liste : « p.account IN (liste) » en condition principale permet à la base
        // de parcourir l'index (account_id, published_at) de ces seuls comptes. Avec « p.account = :viewer OR
        // p.account IN (sous-requête) », MariaDB lisait toute la table posts (fédérées comprises) puis la triait.
        /** @var list<Uuid|string> $followed */
        $followed = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(f.following) AS id')
            ->from(Follow::class, 'f')
            ->where('f.follower = :viewer')
            ->andWhere('f.state = :accepted')
            ->setParameter('viewer', $viewer->getId(), UuidType::NAME)
            ->setParameter('accepted', FollowState::Accepted)
            ->getQuery()
            ->getSingleColumnResult();

        $qb = $this->base()
            ->where('p.account IN (:authors)')
            ->andWhere(\sprintf('p.account = :viewer OR p.visibility IN (:shared) OR (p.visibility = :groups AND %s)', self::MEMBER_OF_GROUPS))
            ->setParameter('authors', $this->uuidList([$viewer->getId(), ...$followed]), ArrayParameterType::STRING)
            ->setParameter('viewer', $viewer->getId(), UuidType::NAME)
            ->setParameter('shared', [Visibility::Public, Visibility::Unlisted, Visibility::Followers])
            ->setParameter('groups', Visibility::Groups);

        return $this->page($qb, $cursor, $limit);
    }

    /**
     * Fil « Local » : publications publiques des comptes de l'instance.
     */
    public function localTimeline(?Cursor $cursor, int $limit): TimelinePage
    {
        $qb = $this->base()
            ->where('a.domain IS NULL')
            ->andWhere('p.visibility = :public')
            ->setParameter('public', Visibility::Public);

        return $this->page($qb, $cursor, $limit);
    }

    /**
     * Fil « Fédéré » : publications publiques reçues des autres instances.
     */
    public function federatedTimeline(?Cursor $cursor, int $limit): TimelinePage
    {
        $qb = $this->base()
            ->where('a.domain IS NOT NULL')
            ->andWhere('p.visibility = :public')
            ->setParameter('public', Visibility::Public);

        return $this->page($qb, $cursor, $limit);
    }

    /**
     * Publications d'un profil, filtrées selon ce que le visiteur a le droit de voir.
     *
     * @param list<Visibility> $visible
     */
    public function profileTimeline(Account $account, array $visible, ?Cursor $cursor, int $limit, ?Account $viewer = null): TimelinePage
    {
        $qb = $this->base()
            ->where('p.account = :account')
            ->setParameter('account', $account->getId(), UuidType::NAME);
        $this->visibleTo($qb, $account, $visible, $viewer);

        return $this->page($qb, $cursor, $limit);
    }

    /**
     * Publications d'un profil plus récentes qu'une position donnée, les plus proches d'abord.
     *
     * @param list<Visibility> $visible
     *
     * @return list<Post> au plus $limit publications, de la plus récente à la plus ancienne
     */
    public function profileNewerThan(Account $account, array $visible, Cursor $from, int $limit, ?Account $viewer = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('a')
            ->join('p.account', 'a')
            ->where('p.account = :account')
            ->andWhere('p.archivedAt IS NULL')
            ->andWhere('p.pending = false')
            ->andWhere('p.publishedAt > :cursorDate OR (p.publishedAt = :cursorDate AND p.id > :cursorId)')
            ->setParameter('account', $account->getId(), UuidType::NAME);
        $this->visibleTo($qb, $account, $visible, $viewer);
        /** @var list<Post> $posts */
        $posts = $qb
            ->setParameter('cursorDate', $from->publishedAt)
            ->setParameter('cursorId', $from->id, UuidType::NAME)
            ->orderBy('p.publishedAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($posts);
    }

    /**
     * @param list<Visibility> $visible
     */
    public function countForProfile(Account $account, array $visible, ?Account $viewer = null): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.account = :account')
            ->andWhere('p.archivedAt IS NULL')
            ->andWhere('p.pending = false')
            ->setParameter('account', $account->getId(), UuidType::NAME);
        $this->visibleTo($qb, $account, $visible, $viewer);

        return (int) $qb->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Publications d'un compte en attente de la conversion de leurs vidéos, les plus récentes d'abord.
     *
     * @return list<Post>
     */
    public function pendingOf(Account $account): array
    {
        /* @var list<Post> */
        return $this->createQueryBuilder('p')
            ->where('p.account = :account')
            ->andWhere('p.pending = true')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->orderBy('p.publishedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Publications archivées d'un compte, de la plus récente à la plus ancienne.
     */
    public function archiveOf(Account $account, ?Cursor $cursor, int $limit): TimelinePage
    {
        $qb = $this->base()
            ->where('p.account = :account')
            ->andWhere('p.archivedAt IS NOT NULL')
            ->setParameter('account', $account->getId(), UuidType::NAME);

        return $this->page($qb, $cursor, $limit, archived: true);
    }

    public function countArchived(Account $account): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.account = :account')
            ->andWhere('p.archivedAt IS NOT NULL')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Publications importées sans annonce au fédivers, de la plus ancienne à la plus récente : celles que
     * leurs abonnés distants peuvent recevoir (ni privées, ni archivées).
     *
     * @return list<Post>
     */
    public function importedNotAnnounced(Account $account): array
    {
        /* @var list<Post> */
        return $this->createQueryBuilder('p')
            ->where('p.account = :account')
            ->andWhere('p.source = :source')
            ->andWhere('p.announce = false')
            ->andWhere('p.archivedAt IS NULL')
            ->andWhere('p.pending = false')
            ->andWhere('p.visibility != :private')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('source', PostSource::InstagramImport)
            ->setParameter('private', Visibility::Private)
            ->orderBy('p.publishedAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Restreint aux visibilités permises ; « Groupes » seulement pour l'autrice ou l'auteur, et pour un visiteur
     * membre d'un des groupes de la publication (l'abonnement est vérifié par PostAccess::visibleFor).
     *
     * @param list<Visibility> $visible
     */
    private function visibleTo(QueryBuilder $qb, Account $author, array $visible, ?Account $viewer): void
    {
        $plain = array_values(array_filter($visible, static fn (Visibility $v): bool => Visibility::Groups !== $v));
        $conditions = [] !== $plain ? ['p.visibility IN (:visible)'] : [];
        if ([] !== $plain) {
            $qb->setParameter('visible', $plain);
        }
        if (\in_array(Visibility::Groups, $visible, true) && $viewer instanceof Account) {
            $conditions[] = $viewer->getId()->equals($author->getId())
                ? 'p.visibility = :groups'
                : 'p.visibility = :groups AND '.self::MEMBER_OF_GROUPS;
            $qb->setParameter('groups', Visibility::Groups);
            if (!$viewer->getId()->equals($author->getId())) {
                $qb->setParameter('viewer', $viewer->getId(), UuidType::NAME);
            }
        }
        $qb->andWhere([] === $conditions ? '1 = 0' : '('.implode(') OR (', $conditions).')');
    }

    /**
     * Identifiants convertis comme le fait le type uuid (binaire, ou texte sur une base à type UUID natif) pour une
     * liste « IN (:ids) » : Doctrine ne sait pas convertir lui-même un tableau d'UUID.
     *
     * @param list<Uuid|string> $ids
     *
     * @return list<string>
     */
    private function uuidList(array $ids): array
    {
        $type = Type::getType(UuidType::NAME);
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        return array_map(static function (Uuid|string $id) use ($type, $platform): string {
            $value = $type->convertToDatabaseValue($id, $platform);
            \assert(\is_string($value));

            return $value;
        }, $ids);
    }

    private function base(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('a')
            ->join('p.account', 'a')
            ->orderBy('p.publishedAt', \SortDirection::Descending)
            ->addOrderBy('p.id', \SortDirection::Descending);
    }

    /**
     * Les fils ne montrent jamais les publications archivées, sauf la page des archives.
     */
    private function page(QueryBuilder $qb, ?Cursor $cursor, int $limit, bool $archived = false): TimelinePage
    {
        if (!$archived) {
            $qb->andWhere('p.archivedAt IS NULL');
        }
        // En attente de la conversion de ses vidéos : seulement dans le bloc « En préparation » de son auteur.
        $qb->andWhere('p.pending = false');
        if ($cursor instanceof Cursor) {
            $qb->andWhere('p.publishedAt < :cursorDate OR (p.publishedAt = :cursorDate AND p.id < :cursorId)')
                ->setParameter('cursorDate', $cursor->publishedAt)
                ->setParameter('cursorId', $cursor->id, UuidType::NAME);
        }

        /** @var list<Post> $posts */
        $posts = $qb->setMaxResults($limit + 1)->getQuery()->getResult();

        $next = null;
        if (\count($posts) > $limit) {
            array_pop($posts);
            $last = array_last($posts);
            \assert(null !== $last);
            $next = Cursor::after($last);
        }

        return new TimelinePage($posts, $next);
    }
}
