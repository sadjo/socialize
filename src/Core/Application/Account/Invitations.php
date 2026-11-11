<?php

declare(strict_types=1);

namespace App\Core\Application\Account;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Account\Invitation;
use App\Core\Infrastructure\Doctrine\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Invitations à créer un compte, lancées par l'administrateur.
 */
final readonly class Invitations
{
    public function __construct(
        private InvitationRepository $invitations,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{invitation: Invitation, token: string} le jeton n'est connu qu'ici : il n'est pas enregistré
     */
    public function create(Account $admin, ?string $email = null): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $email = null !== $email && '' !== trim($email) ? $email : null;
        $invitation = new Invitation(Invitation::hash($token), $admin, $this->clock->now(), $email);
        $this->em->persist($invitation);
        $this->em->flush();

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Invitation correspondant au jeton d'un lien, si elle permet encore de créer un compte.
     */
    public function findUsable(string $token): ?Invitation
    {
        $invitation = $this->invitations->findByToken($token);

        return $invitation instanceof Invitation && $invitation->isUsable($this->clock->now()) ? $invitation : null;
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->revoke($this->clock->now());
        $this->em->flush();
    }

    /**
     * @return list<Invitation>
     */
    public function recent(): array
    {
        return $this->invitations->recent();
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
