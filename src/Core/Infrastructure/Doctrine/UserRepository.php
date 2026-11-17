<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Account\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /**
     * Connexion avec l'adresse e-mail ou le nom d'utilisateur local (« camille » ou « @camille »).
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if (str_contains($identifier, '@') && !str_starts_with($identifier, '@')) {
            return $this->findOneByEmail($identifier);
        }

        $user = $this->createQueryBuilder('u')
            ->join('u.account', 'a')
            ->where('a.username = :username')
            ->andWhere('a.domain IS NULL')
            ->setParameter('username', mb_strtolower(ltrim($identifier, '@')))
            ->getQuery()
            ->getOneOrNullResult();
        \assert(null === $user || $user instanceof User);

        return $user;
    }

    /**
     * Comptes de l'instance, les plus récents d'abord (administration).
     *
     * @return list<User>
     */
    public function recent(int $limit = 200): array
    {
        /* @var list<User> */
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Administrateurs de l'instance (une poignée de comptes : filtrés ici plutôt que dans le JSON des rôles, dont la
     * recherche diffère entre SQLite et MySQL / MariaDB).
     *
     * @return list<User>
     */
    public function admins(): array
    {
        /** @var list<User> $users */
        $users = $this->findAll();

        return array_values(array_filter($users, static fn (User $user): bool => $user->isAdmin()));
    }

    public function hasAny(): bool
    {
        return null !== $this->createQueryBuilder('u')->select('u.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->changePassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
