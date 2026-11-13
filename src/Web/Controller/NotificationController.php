<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Notification\Notifications;
use App\Core\Domain\Notification\Notification;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Notifications du compte : J'aime et commentaires reçus, lus ou non lus, supprimés un à un ou tous ensemble.
 */
#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    private const string TOKEN = 'notifications';

    public function __construct(
        private readonly Notifications $notifications,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    #[Route('/notifications', name: 'app_notifications', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('notification/index.html.twig', [
            'notifications' => $this->notifications->of($this->currentAccount->require()),
        ]);
    }

    /**
     * Ouvre la publication (au commentaire s'il y en a un) et marque la notification comme lue.
     */
    #[Route('/notifications/{id}/ouvrir', name: 'app_notification_open', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function open(string $id, Request $request): RedirectResponse
    {
        $notification = $this->notification($id, $request);
        $this->notifications->markRead($notification);
        $comment = $notification->getComment();

        return $this->redirectToRoute('app_post_show', [
            'id' => $notification->getPost()->getPublicId(),
            '_fragment' => $comment instanceof \App\Core\Domain\Social\Comment ? 'comment-'.$comment->getPublicId() : null,
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/notifications/{id}/lue', name: 'app_notification_toggle', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function toggle(string $id, Request $request): RedirectResponse
    {
        $notification = $this->notification($id, $request);
        $notification->isRead() ? $this->notifications->markUnread($notification) : $this->notifications->markRead($notification);

        return $this->back();
    }

    #[Route('/notifications/{id}/supprimer', name: 'app_notification_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->notifications->delete($this->notification($id, $request));

        return $this->back();
    }

    #[Route('/notifications/tout-lu', name: 'app_notifications_read_all', methods: ['POST'])]
    public function readAll(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->notifications->markAllRead($this->currentAccount->require());

        return $this->back();
    }

    #[Route('/notifications/tout-supprimer', name: 'app_notifications_delete_all', methods: ['POST'])]
    public function deleteAll(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->notifications->deleteAll($this->currentAccount->require());
        $this->addFlash('success', 'Notifications supprimées.');

        return $this->back();
    }

    private function notification(string $id, Request $request): Notification
    {
        $this->checkToken($request);

        return $this->notifications->find($this->currentAccount->require(), $id) ?? throw new NotFoundHttpException();
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
    }

    private function back(): RedirectResponse
    {
        return $this->redirectToRoute('app_notifications', status: Response::HTTP_SEE_OTHER);
    }
}
