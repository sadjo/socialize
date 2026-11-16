<?php

declare(strict_types=1);

namespace App\Core\Application\Notification;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Envoie une notification à chaque appareil du compte ; un appareil dont l'abonnement n'existe plus est oublié.
 */
#[AsMessageHandler]
final readonly class SendPushHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private PushSubscriptionRepository $subscriptions,
        private PushSender $sender,
    ) {
    }

    public function __invoke(SendPush $message): void
    {
        $account = $this->em->find(Account::class, Uuid::fromString($message->accountId));
        if (!$account instanceof Account) {
            return;
        }
        foreach ($this->subscriptions->of($account) as $subscription) {
            if (!$this->sender->send($subscription, $message->payload)) {
                $this->em->remove($subscription);
            }
        }
        $this->em->flush();
    }
}
