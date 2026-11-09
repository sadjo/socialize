<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Integration\Immich\Application\ImmichConnections;
use App\Integration\Immich\Application\ImmichException;
use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\ImmichClient;
use App\Web\Form\ImmichSettingsType;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Immich : connexion du compte (réglages), photothèque et vignettes pour choisir des médias à publier.
 */
#[IsGranted('ROLE_USER')]
final class ImmichController extends AbstractController
{
    public function __construct(
        private readonly ImmichConnections $connections,
        private readonly ImmichClient $client,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    #[Route('/reglages/immich', name: 'app_settings_immich', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $account = $this->currentAccount->require();
        $connection = $this->connections->of($account);
        $form = $this->createForm(ImmichSettingsType::class, [
            'serverUrl' => $connection?->getServerUrl(),
            'addToAlbum' => $connection?->addsToAlbum() ?? false,
        ], ['connected' => $connection instanceof ImmichConnection]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{serverUrl: string, apiKey: ?string, addToAlbum: bool} $data */
            $data = $form->getData();
            try {
                $check = $this->connections->connect($account, $data['serverUrl'], $data['apiKey'], $data['addToAlbum']);
                $this->addFlash('success', \sprintf(
                    'Immich connecté (%s, version %s) : %s photo%s, %s vidéo%s, %d album%s.',
                    $check['user'], $check['version'],
                    number_format($check['images'], 0, ',', ' '), $check['images'] > 1 ? 's' : '',
                    number_format($check['videos'], 0, ',', ' '), $check['videos'] > 1 ? 's' : '',
                    $check['albums'], $check['albums'] > 1 ? 's' : '',
                ));

                return $this->redirectToRoute('app_settings_immich', status: Response::HTTP_SEE_OTHER);
            } catch (ImmichException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('settings/immich.html.twig', ['form' => $form, 'connection' => $connection, 'account' => $account]);
    }

    #[Route('/reglages/immich/deconnecter', name: 'app_settings_immich_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('immich-disconnect', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $this->connections->disconnect($this->currentAccount->require());
        $this->addFlash('success', 'Immich déconnecté. Les publications déjà faites ne changent pas.');

        return $this->redirectToRoute('app_settings_immich', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Une page de la photothèque (ou d'un album), pour la source Immich de la création d'une publication ou d'une story.
     */
    #[Route('/immich/phototheque', name: 'app_immich_library', methods: ['GET'])]
    public function library(Request $request): Response
    {
        $connection = $this->connection();
        [$server, $key] = $this->connections->credentials($connection);
        $album = $request->query->getString('album');
        $album = preg_match('/^[0-9a-f-]{36}$/i', $album) ? $album : null;
        $page = max(1, $request->query->getInt('page', 1));
        // « Aller au » jour choisi : les médias pris ce jour-là ou avant, du plus récent au plus ancien.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date')) ?: null;
        $date = $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $request->query->getString('date') ? $date : null;

        try {
            $result = $this->client->library($server, $key, $page, $album, $date?->modify('+1 day'));
            $error = null;
        } catch (ImmichException $e) {
            $result = ['assets' => [], 'next' => null];
            $error = $e->getMessage();
        }

        $days = [];
        foreach ($result['assets'] as $asset) {
            $days[$asset->takenAt->format('Y-m-d')][] = $asset;
        }

        return $this->render('compose/_immich_page.html.twig', [
            'days' => $days,
            'page' => $page,
            'next' => $result['next'],
            'album' => $album,
            'date' => $date,
            'error' => $error,
        ]);
    }

    /**
     * Vignette d'un média Immich, relayée par le serveur : la clé API ne quitte jamais Socialize.
     */
    #[Route('/immich/apercu/{asset}', name: 'app_immich_thumbnail', requirements: ['asset' => Requirement::UUID], methods: ['GET'])]
    public function thumbnail(string $asset): StreamedResponse
    {
        [$server, $key] = $this->connections->credentials($this->connection());
        try {
            $upstream = $this->client->thumbnail($server, $key, $asset);
        } catch (ImmichException) {
            throw new NotFoundHttpException('Vignette introuvable dans Immich.');
        }
        $type = $upstream->getHeaders(false)['content-type'][0] ?? 'image/webp';
        $chunks = $this->client->chunks($upstream);

        $response = new StreamedResponse(static function () use ($chunks): void {
            foreach ($chunks as $chunk) {
                echo $chunk;
                flush();
            }
        }, headers: ['Content-Type' => $type]);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    private function connection(): ImmichConnection
    {
        return $this->connections->of($this->currentAccount->require())
            ?? throw new NotFoundHttpException('Connectez Immich dans les réglages pour parcourir votre photothèque.');
    }
}
