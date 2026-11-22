<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Core\Application\Account\Invitations;
use App\Core\Domain\Account\Invitation;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\InvitationRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace Administration : invitations à créer un compte, par e-mail ou par un lien à copier.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly Invitations $invitations,
        // Paresseux : un MAILER_DSN invalide ne doit pas empêcher d'afficher la page ni de créer un lien.
        #[Autowire(lazy: true)]
        private readonly MailerInterface $mailer,
    ) {
    }

    #[Route('/admin/invitations', name: 'admin_invitations', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(InvitationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: ?string} $data */
            $data = $form->getData();
            /** @var User $admin */
            $admin = $this->getUser();
            ['invitation' => $invitation, 'token' => $token] = $this->invitations->create($admin->getAccount(), $data['email']);
            $link = $this->generateUrl('app_invitation', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

            if (null !== $invitation->getEmail()) {
                try {
                    $this->mailer->send(new TemplatedEmail()
                        ->to(new Address($invitation->getEmail()))
                        ->subject(\sprintf('%s vous invite sur Socialize', $admin->getAccount()->getDisplayName()))
                        ->htmlTemplate('emails/invitation.html.twig')
                        ->textTemplate('emails/invitation.txt.twig')
                        ->context(['invitation' => $invitation, 'link' => $link, 'inviter' => $admin->getAccount()]));
                    $this->addFlash('success', \sprintf('Invitation envoyée à %s.', $invitation->getEmail()));
                } catch (MailerException) {
                    $this->addFlash('error', \sprintf('L’e-mail n’a pas pu être envoyé à %s (serveur d’envoi : MAILER_DSN). Transmettez le lien ci-dessous vous-même.', $invitation->getEmail()));
                }
            } else {
                $this->addFlash('success', 'Lien d’invitation créé.');
            }
            // Le lien n'est montré qu'une fois : seule son empreinte est enregistrée.
            $this->addFlash('invitation_link', $link);

            return $this->redirectToRoute('admin_invitations', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/invitations.html.twig', [
            'form' => $form,
            'invitations' => $this->invitations->recent(),
            'now' => $this->invitations->now(),
        ]);
    }

    #[Route('/admin/invitations/{id}/revoquer', name: 'admin_invitation_revoke', requirements: ['id' => Requirement::UID_BASE58], methods: ['POST'])]
    public function revoke(string $id, Request $request, InvitationRepository $repository): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $invitation = $repository->findByPublicId($id);
        if (!$invitation instanceof Invitation) {
            throw $this->createNotFoundException('Invitation introuvable.');
        }
        if (!$this->isCsrfTokenValid('invitation-revoke-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $this->invitations->revoke($invitation);
        $this->addFlash('success', 'Invitation révoquée : son lien ne permet plus de créer un compte.');

        return $this->redirectToRoute('admin_invitations', status: Response::HTTP_SEE_OTHER);
    }
}
