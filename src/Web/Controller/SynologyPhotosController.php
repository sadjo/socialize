<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Account\User;
use App\Integration\SynologyPhotos\Application\SynologyPhotosConnections;
use App\Integration\SynologyPhotos\Application\SynologyPhotosException;
use App\Integration\SynologyPhotos\Application\SynologyPhotosItem;
use App\Integration\SynologyPhotos\Domain\SynologyPhotosConnection;
use App\Integration\SynologyPhotos\Infrastructure\SynologyPhotosClient;
use App\Media\Application\MediaRules;
use App\Web\Form\SynologyPhotosSettingsType;
use App\Web\Http\ReleaseSession;
use App\Web\Http\UpstreamRelay;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Synology Photos : connexion du compte (réglages), photothèque et vignettes pour choisir des médias à publier.
 */
#[IsGranted(User::ROLE_PUBLISHER, message: 'Votre compte n’a pas le droit de publier.')]
final class SynologyPhotosController extends AbstractController
{
    public function __construct(
        private readonly SynologyPhotosConnections $connections,
        private readonly SynologyPhotosClient $client,
        private readonly CurrentAccount $currentAccount,
        private readonly Filesystem $filesystem,
    ) {
    }

    #[Route('/reglages/synology-photos', name: 'app_settings_synology_photos', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $account = $this->currentAccount->require();
        $connection = $this->connections->of($account);
        $form = $this->createForm(SynologyPhotosSettingsType::class, [
            'serverUrl' => $connection?->getServerUrl(),
            'userName' => $connection?->getUserName(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{serverUrl: string, userName: string, password: string, otp: ?string} $data */
            $data = $form->getData();
            try {
                $check = $this->connections->connect($account, $data['serverUrl'], $data['userName'], $data['password'], $data['otp']);
                $this->addFlash('success', \sprintf(
                    'Synology Photos connecté (%s) : %s élément%s dans l’espace personnel%s, %d album%s.',
                    $check['user'],
                    number_format($check['personal'], 0, ',', ' '), $check['personal'] > 1 ? 's' : '',
                    null !== $check['team'] ? \sprintf(', %s dans l’espace partagé', number_format($check['team'], 0, ',', ' ')) : '',
                    $check['albums'], $check['albums'] > 1 ? 's' : '',
                ));

                return $this->redirectToRoute('app_settings_synology_photos', status: Response::HTTP_SEE_OTHER);
            } catch (SynologyPhotosException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('settings/synology_photos.html.twig', ['form' => $form, 'connection' => $connection, 'account' => $account]);
    }

    #[Route('/reglages/synology-photos/deconnecter', name: 'app_settings_synology_photos_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('synology-photos-disconnect', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $this->connections->disconnect($this->currentAccount->require());
        $this->addFlash('success', 'Synology Photos déconnecté. Les publications déjà faites ne changent pas.');

        return $this->redirectToRoute('app_settings_synology_photos', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Une page d'un espace (ou d'un album), pour la source Synology Photos de la création d'une publication ou d'une story.
     */
    #[Route('/synology-photos/phototheque', name: 'app_synology_photos_library', methods: ['GET'])]
    #[ReleaseSession]
    public function library(Request $request): Response
    {
        $connection = $this->connection();
        $space = SynologyPhotosItem::TEAM === $request->query->getString('espace') ? SynologyPhotosItem::TEAM : SynologyPhotosItem::PERSONAL;
        $album = $request->query->getInt('album') > 0 ? $request->query->getInt('album') : null;
        $page = max(1, $request->query->getInt('page', 1));
        // « Aller au » jour choisi : les médias pris ce jour-là ou avant. Les heures du NAS sont des heures locales en UTC.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date'), new \DateTimeZone('UTC')) ?: null;
        $date = $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $request->query->getString('date') ? $date : null;

        $expired = false;
        try {
            [$server, $session] = $this->connections->session($connection);
            $result = $this->client->library($server, $session, $space, $page, $album, $date?->modify('+1 day'));
            $error = null;
        } catch (SynologyPhotosException $e) {
            $this->connections->noteFailure($connection, $e);
            $result = ['items' => [], 'next' => null];
            $error = $e->getMessage();
            $expired = $e->sessionExpired;
        }

        $days = [];
        foreach ($result['items'] as $item) {
            $days[$item->takenAt->format('Y-m-d')][] = $item;
        }

        return $this->render('compose/_synology_photos_page.html.twig', [
            'days' => $days,
            'page' => $page,
            'next' => $result['next'],
            'space' => $space,
            'album' => $album,
            'date' => $date,
            'error' => $error,
            'expired' => $expired,
        ]);
    }

    /**
     * Vignette d'un média, relayée par le serveur : la session DSM ne quitte jamais Socialize.
     */
    #[Route('/synology-photos/apercu/{space}/{cacheKey}', name: 'app_synology_photos_thumbnail', requirements: ['space' => '[pt]', 'cacheKey' => '\d{1,18}_\d{1,18}'], methods: ['GET'])]
    #[ReleaseSession]
    public function thumbnail(string $space, string $cacheKey): StreamedResponse
    {
        return $this->image($space, $cacheKey, 'sm');
    }

    /**
     * Image en grand d'un média, pour l'aperçu de la création.
     */
    #[Route('/synology-photos/grand/{space}/{cacheKey}', name: 'app_synology_photos_preview', requirements: ['space' => '[pt]', 'cacheKey' => '\d{1,18}_\d{1,18}'], methods: ['GET'])]
    #[ReleaseSession]
    public function preview(string $space, string $cacheKey): StreamedResponse
    {
        return $this->image($space, $cacheKey, 'xl');
    }

    /**
     * Version convertie d'une vidéo, relayée par le serveur (lecture et déplacement par plages), pour l'aperçu.
     */
    #[Route('/synology-photos/video/{space}/{id}/{quality}', name: 'app_synology_photos_video', requirements: ['space' => '[pt]', 'id' => '\d{1,18}', 'quality' => '[a-z0-9_]{1,20}'], methods: ['GET'])]
    #[ReleaseSession]
    public function video(string $space, int $id, string $quality, Request $request): StreamedResponse
    {
        if (!\in_array($quality, SynologyPhotosItem::STREAM_QUALITIES, true)) {
            throw new NotFoundHttpException('Version de vidéo inconnue.');
        }
        $connection = $this->connection();
        try {
            [$server, $session] = $this->connections->session($connection);
            $upstream = $this->client->stream($server, $session, $this->space($space), $id, $quality, UpstreamRelay::rangeHeaders($request));
        } catch (SynologyPhotosException $e) {
            $this->connections->noteFailure($connection, $e);
            throw new NotFoundHttpException('Vidéo introuvable dans Synology Photos.', $e);
        }

        return UpstreamRelay::respond($upstream, $this->client->chunks($upstream), 'video/', 'video/mp4', 3600);
    }

    /**
     * Courte vidéo d'une photo live, pour l'aperçu. Synology Photos ne la donne que dans l'archive de la photo : elle
     * en est extraite dans un fichier temporaire, servi par plages puis supprimé.
     */
    #[Route('/synology-photos/live/{space}/{id}', name: 'app_synology_photos_live', requirements: ['space' => '[pt]', 'id' => '\d{1,18}'], methods: ['GET'])]
    #[ReleaseSession]
    public function live(string $space, int $id): BinaryFileResponse
    {
        $connection = $this->connection();
        $path = $this->filesystem->tempnam(sys_get_temp_dir(), 'socialize-synology-live-', '.mov');
        try {
            [$server, $session] = $this->connections->session($connection);
            $this->client->liveVideo($server, $session, $this->space($space), $id, $path, (int) MediaRules::MAX_VIDEO_SIZE * 1024 * 1024);
        } catch (SynologyPhotosException $e) {
            $this->filesystem->remove($path);
            $this->connections->noteFailure($connection, $e);
            throw new NotFoundHttpException('Vidéo de la photo live introuvable dans Synology Photos.', $e);
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => (string) new \finfo(\FILEINFO_MIME_TYPE)->file($path)], autoEtag: true);
        $response->deleteFileAfterSend();
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * @param 'sm'|'xl' $size
     */
    private function image(string $space, string $cacheKey, string $size): StreamedResponse
    {
        $connection = $this->connection();
        try {
            [$server, $session] = $this->connections->session($connection);
            $upstream = $this->client->thumbnail($server, $session, $this->space($space), $cacheKey, $size);
        } catch (SynologyPhotosException $e) {
            $this->connections->noteFailure($connection, $e);
            throw new NotFoundHttpException('Image introuvable dans Synology Photos.', $e);
        }

        return UpstreamRelay::respond($upstream, $this->client->chunks($upstream), 'image/', 'image/jpeg');
    }

    /**
     * @return SynologyPhotosItem::PERSONAL|SynologyPhotosItem::TEAM
     */
    private function space(string $space): string
    {
        return SynologyPhotosItem::TEAM === $space ? SynologyPhotosItem::TEAM : SynologyPhotosItem::PERSONAL;
    }

    private function connection(): SynologyPhotosConnection
    {
        return $this->connections->of($this->currentAccount->require())
            ?? throw new NotFoundHttpException('Connectez Synology Photos dans les réglages pour parcourir votre photothèque.');
    }
}
