<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Music\MusicTrack;
use App\Core\Infrastructure\Doctrine\MusicTrackRepository;
use App\Integration\Openverse\Application\MusicFavorites;
use App\Integration\Openverse\Application\OpenverseException;
use App\Integration\Openverse\Application\OpenverseTrack;
use App\Integration\Openverse\Infrastructure\OpenverseClient;
use App\Media\Application\MediaUrls;
use App\Web\Controller\Payload\MusicSearch;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Sélecteur de musique libre (Openverse) des écrans de création et de modification.
 */
#[IsGranted('ROLE_USER')]
final class MusicController extends AbstractController
{
    public const string FAVORITE_TOKEN = 'music-favorite';
    /** Identifiant Openverse : un UUID, quelle que soit sa version. */
    private const string OPENVERSE_ID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly MusicFavorites $favorites,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    /**
     * Une page de résultats, insérée telle quelle dans le sélecteur.
     */
    #[Route('/musique/recherche', name: 'app_music_search', methods: ['GET'])]
    public function search(OpenverseClient $openverse, #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] MusicSearch $search = new MusicSearch()): Response
    {
        try {
            $result = $openverse->search($search->q, $search->page);
            $error = null;
        } catch (OpenverseException $e) {
            $result = ['tracks' => [], 'next' => null];
            $error = $e->getMessage();
        }

        return $this->render('compose/_music_results.html.twig', [
            'query' => $search->q,
            'page' => $search->page,
            'tracks' => $result['tracks'],
            'next' => $result['next'],
            'error' => $error,
            'favorites' => $this->favorites->among($this->currentAccount->require(), $result['tracks']),
            'favorites_view' => false,
        ]);
    }

    /**
     * Musiques favorites du compte, montrées à l'ouverture du sélecteur, avant toute recherche.
     */
    #[Route('/musique/favoris', name: 'app_music_favorites', methods: ['GET'])]
    public function favorites(): Response
    {
        $tracks = $this->favorites->of($this->currentAccount->require());

        return $this->render('compose/_music_results.html.twig', [
            'query' => '',
            'page' => 1,
            'tracks' => $tracks,
            'next' => null,
            'error' => null,
            'favorites' => array_fill_keys(array_map(static fn (OpenverseTrack $t): string => $t->id, $tracks), true),
            'favorites_view' => true,
        ]);
    }

    /**
     * Ajout aux favoris, ou retrait s'il y est déjà.
     */
    #[Route('/musique/favoris/{openverseId}', name: 'app_music_favorite', requirements: ['openverseId' => self::OPENVERSE_ID], methods: ['POST'])]
    public function favorite(string $openverseId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::FAVORITE_TOKEN, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Jeton de sécurité invalide. Rechargez la page et réessayez.'], Response::HTTP_FORBIDDEN);
        }
        try {
            return $this->json(['favorite' => $this->favorites->toggle($this->currentAccount->require(), $openverseId)]);
        } catch (OpenverseException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Vignette d'un morceau, relayée par le serveur : le navigateur ne contacte pas Openverse.
     */
    #[Route('/musique/vignette/{openverseId}', name: 'app_music_thumbnail', requirements: ['openverseId' => self::OPENVERSE_ID], methods: ['GET'])]
    public function thumbnail(string $openverseId, OpenverseClient $openverse): StreamedResponse
    {
        try {
            $upstream = $openverse->thumbnail($openverseId);
            $type = $upstream->getHeaders(false)['content-type'][0] ?? 'image/jpeg';
        } catch (OpenverseException|ExceptionInterface) {
            throw $this->createNotFoundException('Vignette introuvable.');
        }
        $chunks = $openverse->chunks($upstream);

        $response = new StreamedResponse(static function () use ($chunks): void {
            try {
                foreach ($chunks as $chunk) {
                    echo $chunk;
                    flush();
                }
            } catch (OpenverseException) {
                // Vignette interrompue : le navigateur affichera l'icône de remplacement.
            }
        }, headers: ['Content-Type' => $type]);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    /**
     * Pré-écoute d'un morceau sans le stocker : le fichier est relayé depuis son fournisseur (le navigateur
     * ne le contacte jamais), plage par plage pour pouvoir se déplacer. Un morceau déjà copié sur
     * l'instance est servi par sa copie.
     */
    #[Route('/musique/ecouter/{openverseId}', name: 'app_music_listen', requirements: ['openverseId' => self::OPENVERSE_ID], methods: ['GET'])]
    public function listen(string $openverseId, Request $request, OpenverseClient $openverse, MusicTrackRepository $tracks, MediaUrls $urls): Response
    {
        $local = $tracks->findByOpenverseId(strtolower($openverseId));
        if ($local instanceof MusicTrack) {
            return $this->redirect($urls->path($local->getPath()));
        }

        try {
            $upstream = $openverse->open($openverse->track($openverseId), $request->headers->get('Range'));
            $source = $upstream->getHeaders(false);
        } catch (OpenverseException|ExceptionInterface) {
            throw $this->createNotFoundException('Ce morceau ne peut pas être écouté pour le moment.');
        }

        $headers = ['Content-Type' => $source['content-type'][0] ?? 'audio/mpeg', 'Accept-Ranges' => 'bytes'];
        foreach (['content-length' => 'Content-Length', 'content-range' => 'Content-Range'] as $name => $header) {
            if (isset($source[$name][0])) {
                $headers[$header] = $source[$name][0];
            }
        }
        $chunks = $openverse->chunks($upstream);
        // La session est libérée avant le flux : la lecture ne bloque pas les autres pages.
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $response = new StreamedResponse(static function () use ($chunks): void {
            try {
                foreach ($chunks as $chunk) {
                    echo $chunk;
                    flush();
                }
            } catch (OpenverseException) {
                // Lecture interrompue chez le fournisseur : le navigateur redemandera la suite.
            }
        }, $upstream->getStatusCode(), $headers);
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }
}
