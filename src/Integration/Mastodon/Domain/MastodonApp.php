<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Domain;

use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonAppRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Application OAuth de Socialize sur un serveur Mastodon : enregistrée une fois par serveur, à la première
 * connexion d'un de ses comptes. Son secret est chiffré au repos.
 */
#[ORM\Entity(repositoryClass: MastodonAppRepository::class)]
#[ORM\Table(name: 'mastodon_apps')]
class MastodonApp
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\Column(length: 253, unique: true)]
        private string $domain,
        #[ORM\Column(length: 255)]
        private string $clientId,
        #[ORM\Column(type: Types::TEXT)]
        private string $clientSecretEncrypted,
        /** Adresse de retour déclarée à l'enregistrement : si celle de l'instance change, l'application est refaite. */
        #[ORM\Column(length: 500)]
        private string $redirectUri,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientSecretEncrypted(): string
    {
        return $this->clientSecretEncrypted;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function replace(string $clientId, string $clientSecretEncrypted, string $redirectUri, \DateTimeImmutable $now): void
    {
        $this->clientId = $clientId;
        $this->clientSecretEncrypted = $clientSecretEncrypted;
        $this->redirectUri = $redirectUri;
        $this->createdAt = $now;
    }
}
