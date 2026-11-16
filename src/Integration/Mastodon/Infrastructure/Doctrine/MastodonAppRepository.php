<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Infrastructure\Doctrine;

use App\Integration\Mastodon\Domain\MastodonApp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MastodonApp>
 */
final class MastodonAppRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MastodonApp::class);
    }

    public function forDomain(string $domain): ?MastodonApp
    {
        return $this->findOneBy(['domain' => $domain]);
    }
}
