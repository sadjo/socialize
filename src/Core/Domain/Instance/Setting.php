<?php

declare(strict_types=1);

namespace App\Core\Domain\Instance;

use App\Core\Infrastructure\Doctrine\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage de l'instance (clé et valeur), par exemple les clés de l'acteur d'instance.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'instance_settings')]
class Setting
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(type: Types::TEXT)]
        private string $value,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function change(string $value): void
    {
        $this->value = $value;
    }
}
