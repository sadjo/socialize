<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Web\Form\ForgotPasswordType;
use App\Web\Form\ResetPasswordType;
use App\Web\Security\PasswordReset;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mot de passe oublié » des comptes de l'instance : lien envoyé par e-mail, puis choix d'un nouveau mot de passe.
 */
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordReset $reset,
        // Paresseux : un MAILER_DSN invalide ne doit pas empêcher d'afficher la page.
        #[Autowire(lazy: true)]
        private readonly MailerInterface $mailer,
    ) {
    }

    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $users,
        LoggerInterface $logger,
        RateLimiterFactoryInterface $passwordResetLimiter,
        RateLimiterFactoryInterface $passwordResetIpLimiter,
    ): Response {
        if ($this->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return $this->redirectToRoute('app_home');
        }
        $form = $this->createForm(ForgotPasswordType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{identifier: string} $data */
            $data = $form->getData();
            if (!$passwordResetIpLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError('Trop de demandes depuis votre connexion : réessayez dans une heure.'));
            } else {
                $user = $users->loadUserByIdentifier($data['identifier']);
                // Même réponse que le compte existe ou non : la page ne dit pas quels comptes existent.
                if ($user instanceof User && $passwordResetLimiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
                    try {
                        $this->mailer->send(new TemplatedEmail()
                            ->to(new Address($user->getEmail()))
                            ->subject('Choisir un nouveau mot de passe Socialize')
                            ->htmlTemplate('emails/password_reset.html.twig')
                            ->textTemplate('emails/password_reset.txt.twig')
                            ->context(['account' => $user->getAccount(), 'link' => $this->reset->linkFor($user)]));
                    } catch (MailerException $e) {
                        $logger->error('E-mail « Mot de passe oublié » non envoyé (MAILER_DSN) : {message}', ['message' => $e->getMessage()]);
                    }
                }

                return $this->render('security/forgot_password_sent.html.twig');
            }
        }

        return $this->render('security/forgot_password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/mot-de-passe/nouveau', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request): Response
    {
        $user = $this->reset->userFor($request);
        if (!$user instanceof User) {
            return $this->render('security/reset_password_invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }
        $form = $this->createForm(ResetPasswordType::class, options: ['action' => $request->getUri()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{newPassword: string} $data */
            $data = $form->getData();
            $this->reset->reset($user, $data['newPassword']);
            $this->addFlash('success', 'Mot de passe changé : connectez-vous avec le nouveau.');

            return $this->redirectToRoute('app_login', ['onglet' => 'socialize'], Response::HTTP_SEE_OTHER);
        }

        return $this->render('security/reset_password.html.twig', ['form' => $form, 'account' => $user->getAccount()], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
