<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Integration\Mastodon\Application\MastodonException;
use App\Integration\Mastodon\Application\MastodonRegistration;
use App\Integration\Mastodon\Application\MastodonSignup;
use App\Integration\Mastodon\Application\MastodonSignupRejected;
use App\Integration\Mastodon\Application\MastodonUnconfirmed;
use App\Web\Form\MastodonSignupType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Création d'un compte Mastodon (MASTODON_SIGNUP) depuis l'écran de connexion, puis connexion avec ce compte une
 * fois son adresse e-mail confirmée. Le jeton du compte attend en session, chiffré, 24 heures au plus.
 */
final class MastodonSignupController extends AbstractController
{
    private const string PENDING = 'mastodon_signup';
    private const int PENDING_TTL = 86400;

    public function __construct(
        private readonly MastodonSignup $signup,
    ) {
    }

    #[Route('/inscription/mastodon', name: 'app_mastodon_signup', methods: ['GET', 'POST'])]
    public function create(Request $request, RateLimiterFactoryInterface $mastodonSignupLimiter): Response
    {
        if ($this->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return $this->redirectToRoute('app_home');
        }
        $registration = $this->signup->registration();
        if (!$registration instanceof MastodonRegistration) {
            throw new NotFoundHttpException(null !== $this->signup->server() ? 'Le serveur Mastodon ne répond pas : réessayez plus tard.' : 'La création de compte Mastodon n’est pas proposée ici.');
        }
        if (!$registration->open) {
            return $this->render('security/mastodon_signup.html.twig', ['registration' => $registration, 'form' => null]);
        }

        $form = $this->createForm(MastodonSignupType::class, options: ['registration' => $registration]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$mastodonSignupLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError('Trop de comptes créés depuis votre connexion : réessayez dans une heure.'));
            } else {
                /** @var array{username: string, email: string, password: string, reason?: ?string, dateOfBirth?: ?\DateTimeImmutable, agreement: bool} $data */
                $data = $form->getData();
                try {
                    $token = $this->signup->register($data, $this->generateUrl('app_mastodon_callback', referenceType: UrlGeneratorInterface::ABSOLUTE_URL));
                    $request->getSession()->set(self::PENDING, ['domain' => $registration->domain, 'username' => $data['username'], 'token' => $token, 'approval' => $registration->approval, 'at' => time()]);

                    return $this->redirectToRoute('app_mastodon_signup_confirm', status: Response::HTTP_SEE_OTHER);
                } catch (MastodonSignupRejected $e) {
                    $this->showErrors($form, $e);
                } catch (MastodonException $e) {
                    $form->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('security/mastodon_signup.html.twig', ['registration' => $registration, 'form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * « Confirmez votre adresse », puis « J'ai confirmé mon adresse » : connexion avec le compte créé.
     */
    #[Route('/inscription/mastodon/confirmation', name: 'app_mastodon_signup_confirm', methods: ['GET', 'POST'])]
    public function confirm(Request $request, Security $security): Response
    {
        $pending = $request->getSession()->get(self::PENDING);
        if (!\is_array($pending) || !\is_string($pending['domain'] ?? null) || !\is_string($pending['token'] ?? null) || !\is_int($pending['at'] ?? null) || $pending['at'] < time() - self::PENDING_TTL) {
            $request->getSession()->remove(self::PENDING);

            return $this->redirectToRoute('app_login');
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mastodon-signup-confirm', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
            }
            try {
                $user = $this->signup->confirm($pending['domain'], $pending['token']);
                $request->getSession()->remove(self::PENDING);
                $security->login($user, 'form_login', 'main');
                $this->addFlash('success', \sprintf('Bienvenue ! Votre compte %s est prêt.', '@'.$user->getAccount()->getAcct()));

                return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
            } catch (MastodonUnconfirmed|MastodonException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('security/mastodon_signup_pending.html.twig', ['pending' => $pending, 'error' => $error], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Refus du serveur affichés sous les champs concernés (les autres, au-dessus du formulaire).
     *
     * @param FormInterface<array<string, mixed>|null> $form
     */
    private function showErrors(FormInterface $form, MastodonSignupRejected $e): void
    {
        $fields = ['username' => 'username', 'email' => 'email', 'password' => 'password', 'reason' => 'reason', 'date_of_birth' => 'dateOfBirth', 'agreement' => 'agreement'];
        if ([] === $e->errors) {
            $form->addError(new FormError($e->getMessage()));
        }
        foreach ($e->errors as $field => $messages) {
            $target = isset($fields[$field]) && $form->has($fields[$field]) ? $form->get($fields[$field]) : $form;
            foreach ($messages as $message) {
                $target->addError(new FormError($message));
            }
        }
    }
}
