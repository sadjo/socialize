<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Application\Notification\Notifications;
use App\Core\Domain\Account\Account;
use App\Web\Security\CurrentAccount;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Nombre de notifications non lues du compte connecté (pastille du bouton Notifications). Gardé le temps d'une
 * requête, remis à zéro entre deux (worker FrankenPHP).
 */
final class NotificationExtension implements ResetInterface
{
    private ?int $count = null;

    public function __construct(
        private readonly Notifications $notifications,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    #[AsTwigFunction('unread_notifications')]
    public function unread(): int
    {
        $account = $this->currentAccount->get();
        if (!$account instanceof Account) {
            return 0;
        }

        // Une seule requête par page, même si la pastille paraît à plusieurs endroits.
        return $this->count ??= $this->notifications->unreadCount($account);
    }

    public function reset(): void
    {
        $this->count = null;
    }
}
