<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Notification\Notifications;
use App\Core\Application\Notification\PushNotifications;
use App\Core\Application\Notification\PushSender;
use App\Core\Domain\Notification\Notification;
use App\Web\Controller\Payload\PushDevice;
use App\Web\Form\NotificationPreferencesType;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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
    private const string DEVICE_TOKEN = 'push-device';

    public function __construct(
        private readonly Notifications $notifications,
        private readonly CurrentAccount $currentAccount,
        private readonly PushNotifications $push,
        private readonly PushSender $sender,
    ) {
    }

    #[Route('/notifications', name: 'app_notifications', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $account = $this->currentAccount->require();
        $preferences = $this->push->preferences($account);
        $form = $this->createForm(NotificationPreferencesType::class, [
            'posts' => $preferences->posts(), 'stories' => $preferences->stories(), 'likes' => $preferences->likes(), 'comments' => $preferences->comments(),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{posts: bool, stories: bool, likes: bool, comments: bool} $data */
            $data = $form->getData();
            $this->push->choose($account, $data['posts'], $data['stories'], $data['likes'], $data['comments']);
            $this->addFlash('success', 'Choix des notifications enregistré.');

            return $this->back();
        }

        return $this->render('notification/index.html.twig', [
            'notifications' => $this->notifications->of($account),
            'preferences_form' => $form,
            'vapid_key' => $this->sender->publicKey(),
            'devices' => $this->push->devicesCount($account),
        ]);
    }

    /**
     * Appareil abonné aux notifications (envoyé par le navigateur après l'accord de la personne).
     */
    #[Route('/notifications/appareils', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(#[MapRequestPayload] PushDevice $device, Request $request): JsonResponse
    {
        $this->checkDeviceToken($request);
        if (!$device->keys instanceof Payload\PushDeviceKeys) {
            return $this->json(['error' => 'Clés de chiffrement manquantes.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->push->subscribe($this->currentAccount->require(), $device->endpoint, $device->keys->p256dh, $device->keys->auth);

        return $this->json(['ok' => true]);
    }

    #[Route('/notifications/appareils/retirer', name: 'app_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(#[MapRequestPayload] PushDevice $device, Request $request): JsonResponse
    {
        $this->checkDeviceToken($request);
        $this->push->unsubscribe($this->currentAccount->require(), $device->endpoint);

        return $this->json(['ok' => true]);
    }

    private function checkDeviceToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::DEVICE_TOKEN, (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
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
        $post = $notification->getPost();
        // Nouveau compte (administrateurs) : la liste des comptes, où lui accorder ses droits.
        if (!$post instanceof \App\Core\Domain\Post\Post) {
            return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_post_show', [
            'id' => $post->getPublicId(),
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
