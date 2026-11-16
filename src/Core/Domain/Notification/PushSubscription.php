<?php

declare(strict_types=1);

namespace App\Core\Domain\Notification;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Appareil d'un compte abonné aux notifications (Web Push) : adresse du service de notification du navigateur et
 * clés de chiffrement des messages. Retiré quand le navigateur dit que l'abonnement n'existe plus.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscriptions')]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Empreinte de l'adresse (unique ; l'adresse elle-même peut être longue). */
    #[ORM\Column(length: 64, unique: true)]
    private string $endpointHash;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(type: Types::TEXT)]
        private string $endpoint,
        #[ORM\Column(length: 200)]
        private string $publicKey,
        #[ORM\Column(length: 100)]
        private string $authToken,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->endpointHash = self::hash($endpoint);
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Même appareil, autre compte ou nouvelles clés (réabonnement).
     */
    public function renew(Account $account, string $publicKey, string $authToken): void
    {
        $this->account = $account;
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }
}
