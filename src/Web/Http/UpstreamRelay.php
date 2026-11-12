<?php

declare(strict_types=1);

namespace App\Web\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Relaie au navigateur une image ou une vidéo d'une photothèque externe (Immich, Synology Photos), par flux :
 * la clé ou la session de la photothèque restent sur le serveur. Les demandes partielles (Range) passent, pour
 * qu'une vidéo se lise et se parcoure sans être téléchargée en entier.
 */
final class UpstreamRelay
{
    /** En-têtes de la photothèque transmis au navigateur. */
    private const array PASSED_HEADERS = ['content-length', 'content-range', 'accept-ranges'];

    /**
     * Plage demandée par le navigateur, à transmettre à la photothèque (« bytes=0- », « bytes=100-199 »).
     *
     * @return array<string, string> en-têtes de la requête vers la photothèque
     */
    public static function rangeHeaders(Request $request): array
    {
        $range = (string) $request->headers->get('Range');

        return preg_match('/^bytes=\d*-\d*$/', $range) ? ['Range' => $range] : [];
    }

    /**
     * @param iterable<string> $chunks      contenu, morceau par morceau
     * @param string           $typePrefix  préfixe accepté du type de contenu (« image/ », « video/ »)
     * @param string           $defaultType type utilisé si la photothèque en donne un autre
     */
    public static function respond(ResponseInterface $upstream, iterable $chunks, string $typePrefix, string $defaultType, int $maxAge = 86400): StreamedResponse
    {
        $headers = $upstream->getHeaders(false);
        $type = $headers['content-type'][0] ?? '';
        $passed = ['Content-Type' => str_starts_with($type, $typePrefix) ? $type : $defaultType];
        foreach (self::PASSED_HEADERS as $name) {
            if (isset($headers[$name][0])) {
                $passed[$name] = $headers[$name][0];
            }
        }
        // Réponse partielle sans « Accept-Ranges » (Synology Photos) : le lecteur sait qu'il peut demander des plages.
        if (isset($passed['content-range'])) {
            $passed['accept-ranges'] ??= 'bytes';
        }

        $response = new StreamedResponse(static function () use ($chunks): void {
            foreach ($chunks as $chunk) {
                echo $chunk;
                flush();
            }
        }, Response::HTTP_PARTIAL_CONTENT === $upstream->getStatusCode() ? Response::HTTP_PARTIAL_CONTENT : Response::HTTP_OK, $passed);
        $response->setPrivate();
        $response->setMaxAge($maxAge);

        return $response;
    }
}
