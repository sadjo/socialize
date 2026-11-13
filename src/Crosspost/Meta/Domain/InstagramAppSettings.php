<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Domain;

use App\Core\Domain\Account\Account;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramAppSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Application Meta propre à un compte (identifiant et clé secrète, chiffrée au repos), utilisée pour connecter son
 * Instagram à la place de celle de l'instance.
 */
#[ORM\Entity(repositoryClass: InstagramAppSettingsRepository::class)]
#[ORM\Table(name: 'instagram_apps')]
class InstagramAppSettings
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(length: 64)]
        private string $appId,
        #[ORM\Column(type: Types::TEXT)]
        private string $secretEncrypted,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $updatedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function change(string $appId, ?string $secretEncrypted, \DateTimeImmutable $now): void
    {
        $this->appId = $appId;
        if (null !== $secretEncrypted) {
            $this->secretEncrypted = $secretEncrypted;
        }
        $this->updatedAt = $now;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    public function getSecretEncrypted(): string
    {
        return $this->secretEncrypted;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
