<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Infrastructure\Doctrine;

use App\Core\Application\Account\PilotedAccounts;
use App\Core\Domain\Account\Account;
use App\Integration\Mastodon\Domain\MastodonUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<MastodonUser>
 */
#[AsAlias(PilotedAccounts::class)]
final class MastodonUserRepository extends ServiceEntityRepository implements UserLoaderInterface, PilotedAccounts
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MastodonUser::class);
    }

    public function forAccount(Account $account): ?MastodonUser
    {
        return $this->findOneBy(['account' => $account]);
    }

    /**
     * Compte piloté depuis ici : une personne s'y est connectée et ne s'est pas déconnectée.
     */
    public function isPiloted(Account $account): bool
    {
        return null !== $this->forAccount($account)?->getTokenEncrypted();
    }

    /**
     * Personnes connectées (jeton présent).
     *
     * @return list<MastodonUser>
     */
    public function connected(): array
    {
        /** @var list<MastodonUser> $users */
        $users = $this->createQueryBuilder('u')->andWhere('u.tokenEncrypted IS NOT NULL')->getQuery()->getResult();

        return $users;
    }

    /**
     * Fournisseur d'utilisateurs de la sécurité : seulement une personne connectée (jeton présent).
     */
    public function loadUserByIdentifier(string $identifier): ?MastodonUser
    {
        $user = $this->findOneBy(['identifier' => $identifier]);

        return null !== $user?->getTokenEncrypted() ? $user : null;
    }
}
