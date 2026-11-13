<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Account\User;
use App\Core\Domain\Post\Visibility;
use App\Crosspost\Meta\Application\InstagramConnections;
use App\Crosspost\Meta\Application\InstagramException;
use App\Crosspost\Meta\Application\InstagramPublishing;
use App\Crosspost\Meta\Domain\InstagramConnection;
use App\Crosspost\Meta\Domain\InstagramCrosspost;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramCrosspostRepository;
use App\Web\Form\InstagramRulesType;
use App\Web\Security\CurrentAccount;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Crosspost Instagram : connexion du compte professionnel (OAuth), règles par défaut, nouvel essai d'un envoi.
 */
#[IsGranted(User::ROLE_PUBLISHER, message: 'Votre compte n’a pas le droit de publier.')]
final class InstagramController extends AbstractController
{
    private const string STATE = 'instagram_oauth_state';

    public function __construct(
        private readonly InstagramConnections $connections,
        private readonly CurrentAccount $currentAccount,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $defaultUri,
    ) {
    }

    #[Route('/reglages/instagram', name: 'app_settings_instagram', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $account = $this->currentAccount->require();
        $connection = $this->connections->of($account);
        $form = null;
        if ($connection instanceof InstagramConnection) {
            $form = $this->createForm(InstagramRulesType::class, ['visibilities' => $connection->getAutoVisibilities(), 'stories' => $connection->sharesStories()]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{visibilities: list<Visibility>, stories: bool} $data */
                $data = $form->getData();
                $this->connections->chooseRules($connection, $data['visibilities'], $data['stories']);
                $this->addFlash('success', 'Règles du crosspost Instagram enregistrées.');

                return $this->redirectToRoute('app_settings_instagram', status: Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('settings/instagram.html.twig', [
            'account' => $account,
            'connection' => $connection,
            'expired' => $connection?->isExpired($this->clock->now()) ?? false,
            'available' => $this->connections->isAvailable(),
            'redirect_uri' => $this->redirectUri(),
            'form' => $form,
        ]);
    }

    /**
     * Vers Instagram : l'utilisateur s'y connecte et autorise la publication.
     */
    #[Route('/reglages/instagram/connexion', name: 'app_settings_instagram_connect', methods: ['POST'])]
    public function connect(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('instagram-connect', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        if (!$this->connections->isAvailable()) {
            $this->addFlash('error', 'Le crosspost Instagram n’est pas configuré sur cette instance.');

            return $this->redirectToRoute('app_settings_instagram', status: Response::HTTP_SEE_OTHER);
        }
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::STATE, $state);

        return new RedirectResponse($this->connections->authorizeUrl($this->redirectUri(), $state), Response::HTTP_SEE_OTHER);
    }

    /**
     * Retour d'Instagram : code échangé contre un jeton, après vérification de l'état (contre la falsification).
     */
    #[Route('/reglages/instagram/retour', name: 'app_settings_instagram_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->getSession()->remove(self::STATE);
        $state = $request->query->getString('state');
        if (!\is_string($expected) || '' === $state || !hash_equals($expected, $state)) {
            $this->addFlash('error', 'La connexion à Instagram a expiré ou ne vient pas de cette page : recommencez.');

            return $this->redirectToRoute('app_settings_instagram', status: Response::HTTP_SEE_OTHER);
        }
        if ($request->query->has('error')) {
            $this->addFlash('error', 'Connexion à Instagram annulée.');

            return $this->redirectToRoute('app_settings_instagram', status: Response::HTTP_SEE_OTHER);
        }
        // Instagram ajoute « #_ » à l'adresse de retour : il ne fait pas partie du code.
        $code = (string) preg_replace('/#_$/', '', $request->query->getString('code'));
        try {
            $connection = $this->connections->connect($this->currentAccount->require(), $code, $this->redirectUri());
            $this->addFlash('success', \sprintf('Instagram connecté (@%s).', $connection->getUsername()));
        } catch (InstagramException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_settings_instagram', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/reglages/instagram/deconnecter', name: 'app_settings_instagram_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('instagram-disconnect', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $this->connections->disconnect($this->currentAccount->require());
        $this->addFlash('success', 'Instagram déconnecté. Les publications déjà envoyées restent sur Instagram.');

        return $this->redirectToRoute('app_settings_instagram', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Nouvel essai d'un envoi qui a échoué.
     */
    #[Route('/instagram/envois/{id}/reessayer', name: 'app_instagram_retry', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function retry(string $id, Request $request, InstagramCrosspostRepository $crossposts, InstagramPublishing $publishing): RedirectResponse
    {
        $crosspost = $crossposts->find(Uuid::fromString($id));
        if (!$crosspost instanceof InstagramCrosspost || !$crosspost->getAccount()->getId()->equals($this->currentAccount->require()->getId())) {
            throw new NotFoundHttpException();
        }
        if (!$this->isCsrfTokenValid('instagram-retry-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $publishing->retry($crosspost);
        $this->addFlash('success', 'Nouvel envoi vers Instagram en cours.');
        $post = $crosspost->getPost();

        return $post instanceof \App\Core\Domain\Post\Post
            ? $this->redirectToRoute('app_post_show', ['id' => $post->getPublicId()], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_story_manage', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Adresse de retour déclarée dans l'application Meta : https://<instance>/reglages/instagram/retour.
     */
    private function redirectUri(): string
    {
        $parts = parse_url($this->defaultUri);
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'localhost').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $base.$this->generateUrl('app_settings_instagram_callback');
    }
}
