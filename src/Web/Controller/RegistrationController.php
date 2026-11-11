<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Account\Invitations;
use App\Core\Application\Account\RegisterAccount;
use App\Core\Application\Account\RegistrationException;
use App\Core\Application\Account\RegistrationPolicy;
use App\Core\Domain\Account\Invitation;
use App\Web\Form\RegistrationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, RegistrationPolicy $policy, RegisterAccount $registerAccount, Security $security): Response
    {
        if ($this->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return $this->redirectToRoute('app_home');
        }
        if (!$policy->isOpen()) {
            return $this->render('registration/closed.html.twig', ['mode' => $policy->mode()], new Response(status: Response::HTTP_FORBIDDEN));
        }

        $form = $this->createForm(RegistrationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, username: string, displayName: ?string, password: string} $data */
            $data = $form->getData();
            try {
                $user = $registerAccount->register($data['email'], $data['username'], $data['password'], $data['displayName']);
                $security->login($user, 'form_login', 'main');
                $this->addFlash('success', $user->isAdmin()
                    ? 'Bienvenue ! Vous êtes le premier compte : vous administrez cette instance.'
                    : 'Bienvenue sur Socialize !');

                return $this->redirectToRoute('app_compose');
            } catch (RegistrationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('registration/register.html.twig', [
            'form' => $form,
            'first_account' => $policy->isFirstAccount(),
        ]);
    }

    /**
     * Inscription par invitation (lien ou e-mail de l'administrateur) : possible même quand les inscriptions sont fermées.
     */
    #[Route('/invitation/{token}', name: 'app_invitation', requirements: ['token' => '[A-Za-z0-9_-]{20,100}'], methods: ['GET', 'POST'])]
    public function invitation(string $token, Request $request, Invitations $invitations, RegisterAccount $registerAccount, Security $security): Response
    {
        if ($this->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return $this->redirectToRoute('app_home');
        }
        $invitation = $invitations->findUsable($token);
        if (!$invitation instanceof Invitation) {
            return $this->render('registration/invitation_invalid.html.twig', response: new Response(status: Response::HTTP_GONE));
        }

        $form = $this->createForm(RegistrationType::class, ['email' => $invitation->getEmail()], ['email_locked' => null !== $invitation->getEmail()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: ?string, username: string, displayName: ?string, password: string} $data */
            $data = $form->getData();
            try {
                $user = $registerAccount->register($invitation->getEmail() ?? (string) $data['email'], $data['username'], $data['password'], $data['displayName'], $invitation);
                $security->login($user, 'form_login', 'main');
                $this->addFlash('success', 'Bienvenue sur Socialize !');

                return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
            } catch (RegistrationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('registration/register.html.twig', [
            'form' => $form,
            'first_account' => false,
            'invitation' => $invitation,
        ]);
    }
}
