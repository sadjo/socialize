<?php

declare(strict_types=1);

namespace App\Core\Domain\Account;

use App\Core\Infrastructure\Doctrine\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Personne qui se connecte à l'instance. Son identité publique est son Account.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    /** Droits accordés par l'administration (voir AccountRight), inclus pour un administrateur. */
    public const string ROLE_PUBLISHER = 'ROLE_PUBLISHER';
    public const string ROLE_INSTAGRAM_IMPORTER = 'ROLE_INSTAGRAM_IMPORTER';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\OneToOne(cascade: ['persist'])]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Account $account,
        #[ORM\Column(type: Types::JSON)]
        private array $roles = [],
        #[ORM\Column]
        private string $password = '',
    ) {
        $this->id = Uuid::v7();
        $this->email = mb_strtolower(trim($email));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function promoteToAdmin(): void
    {
        $this->roles = array_values(array_unique([...$this->roles, self::ROLE_ADMIN]));
    }

    /**
     * Le compte dispose-t-il de ce droit ? Toujours pour un administrateur ; l'import Instagram suppose aussi de
     * pouvoir publier.
     */
    public function can(AccountRight $right): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if (AccountRight::InstagramImport === $right && !$this->can(AccountRight::Publish)) {
            return false;
        }

        return $this->isGranted($right);
    }

    /**
     * Droit accordé à ce compte lui-même (sans tenir compte de l'administration ni des droits requis).
     */
    public function isGranted(AccountRight $right): bool
    {
        return \in_array($right->role(), $this->roles, true);
    }

    public function canPublish(): bool
    {
        return $this->can(AccountRight::Publish);
    }

    public function grant(AccountRight $right): void
    {
        $this->roles = array_values(array_unique([...$this->roles, $right->role()]));
    }

    public function revoke(AccountRight $right): void
    {
        $this->roles = array_values(array_filter($this->roles, static fn (string $role): bool => $right->role() !== $role));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function changePassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Les mots de passe ne sont jamais conservés en clair dans l'entité.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
