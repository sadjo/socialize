<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Infrastructure\Doctrine;

use App\Core\Domain\Account\Account;
use App\Crosspost\Meta\Domain\InstagramAppSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstagramAppSettings>
 */
final class InstagramAppSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstagramAppSettings::class);
    }

    public function forAccount(?Account $account): ?InstagramAppSettings
    {
        return $account instanceof Account ? $this->findOneBy(['account' => $account]) : null;
    }
}
