<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Account\Account;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Historique et compteurs d'un compte distant, relus quand on ouvre son profil : au plus une fois par jour, en tâche
 * de fond (BackfillAccountHandler). Son profil montre ainsi ses publications récentes et ses vrais nombres d'abonnés,
 * d'abonnements et de publications, même si personne ne le suit encore ici.
 */
final readonly class RemoteHistory
{
    public const string STALE_AFTER = '-1 day';

    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function refreshIfStale(Account $account): void
    {
        if ($account->isLocal() || null === $account->getOutboxUrl()) {
            return;
        }
        $now = $this->clock->now();
        $last = $account->getBackfilledAt();
        if ($last instanceof \DateTimeImmutable && $last > $now->modify(self::STALE_AFTER)) {
            return;
        }
        // Marqué avant l'envoi : deux ouvertures rapprochées du profil ne lancent qu'une lecture.
        $account->markBackfilled($now);
        $this->em->flush();
        $this->bus->dispatch(new BackfillAccount($account->getId()->toRfc4122()));
    }
}
