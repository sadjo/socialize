<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Integration\Openverse\Application\MusicLibrary;
use App\Integration\Openverse\Application\OpenverseException;
use App\Integration\Openverse\Infrastructure\OpenverseClient;
use App\Media\Application\MediaUrls;
use App\Web\Controller\Payload\MusicSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sélecteur de musique libre (Openverse) des écrans de création et de modification.
 */
#[IsGranted('ROLE_USER')]
final class MusicController extends AbstractController
{
    public const string CHOOSE_TOKEN = 'music-choose';
    /** Identifiant Openverse : un UUID, quelle que soit sa version. */
    private const string OPENVERSE_ID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

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
        ]);
    }

    /**
     * Choix d'un morceau : copie sur l'instance (une fois par morceau), puis lecteur de l'extrait.
     */
    #[Route('/musique/choisir/{openverseId}', name: 'app_music_choose', requirements: ['openverseId' => self::OPENVERSE_ID], methods: ['POST'])]
    public function choose(string $openverseId, Request $request, MusicLibrary $library, MediaUrls $urls): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CHOOSE_TOKEN, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Jeton de sécurité invalide. Rechargez la page et réessayez.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $track = $library->import($openverseId);
        } catch (OpenverseException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id' => $track->getPublicId(),
            'title' => $track->getTitle(),
            'artist' => $track->getArtist(),
            'license' => $track->getLicenseLabel(),
            'duration' => $track->getDuration(),
            'src' => $urls->path($track->getPath()),
        ]);
    }
}
