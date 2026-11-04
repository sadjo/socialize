<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Doctrine;

use App\Core\Domain\Instance\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
final class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function get(string $name): ?string
    {
        return $this->find($name)?->getValue();
    }

    public function set(string $name, string $value): void
    {
        $setting = $this->find($name);
        if (null === $setting) {
            $this->getEntityManager()->persist(new Setting($name, $value));
        } else {
            $setting->change($value);
        }
        $this->getEntityManager()->flush();
    }
}
