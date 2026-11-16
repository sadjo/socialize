<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Integration\Mastodon\Application\MastodonException;
use App\Integration\Mastodon\Application\MastodonLogin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Connexion avec un compte Mastodon : départ vers la page d'autorisation du serveur, puis retour.
 */
final class MastodonLoginController extends AbstractController
{
    /** Connexion en cours, gardée en session le temps de l'aller-retour (10 minutes au plus). */
    private const string PENDING = 'mastodon_login';
    private const int PENDING_TTL = 600;

    public function __construct(
        private readonly MastodonLogin $login,
    ) {
    }

    #[Route('/connexion/mastodon', name: 'app_mastodon_login', methods: ['POST'])]
    public function start(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('mastodon-login', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Rechargez la page et réessayez.');

            return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
        }
        try {
            $pending = $this->login->start($request->request->getString('server'), $this->callbackUrl());
        } catch (MastodonException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
        }
        $request->getSession()->set(self::PENDING, ['domain' => $pending['domain'], 'state' => $pending['state'], 'verifier' => $pending['verifier'], 'at' => time()]);

        return $this->redirect($pending['url'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/connexion/mastodon/retour', name: 'app_mastodon_callback', methods: ['GET'])]
    public function callback(Request $request, Security $security): RedirectResponse
    {
        $pending = $request->getSession()->remove(self::PENDING);
        $state = $request->query->getString('state');
        $code = $request->query->getString('code');
        if (!\is_array($pending) || !\is_string($pending['state'] ?? null) || !\is_string($pending['domain'] ?? null) || !\is_string($pending['verifier'] ?? null)
            || !\is_int($pending['at'] ?? null) || $pending['at'] < time() - self::PENDING_TTL || '' === $state || !hash_equals($pending['state'], $state)) {
            $this->addFlash('error', 'Connexion avec Mastodon interrompue ou expirée : recommencez.');

            return $this->redirectToRoute('app_login');
        }
        if ('' === $code) {
            $this->addFlash('error', 'Connexion avec Mastodon annulée.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $user = $this->login->finish($pending['domain'], $code, $pending['verifier'], $this->callbackUrl());
        } catch (MastodonException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_login');
        }
        $security->login($user, 'form_login', 'main');

        return $this->redirectToRoute('app_home');
    }

    private function callbackUrl(): string
    {
        return $this->generateUrl('app_mastodon_callback', referenceType: UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
