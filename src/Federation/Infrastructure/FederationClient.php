<?php

declare(strict_types=1);

namespace App\Federation\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Requêtes vers les autres instances : signées, limitées en durée et en taille,
 * et jamais vers une adresse du réseau privé (protection contre le SSRF).
 */
final readonly class FederationClient
{
    public const string ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
    private const int MAX_BYTES = 2_000_000;

    private HttpClientInterface $client;

    public function __construct(
        #[Autowire(service: 'federation.http_client')]
        HttpClientInterface $client,
        #[Autowire(env: 'bool:FEDERATION_ALLOW_PRIVATE_NETWORKS')]
        bool $allowPrivateNetworks = false,
    ) {
        $this->client = $allowPrivateNetworks ? $client : new NoPrivateNetworkHttpClient($client);
    }

    /**
     * Lit un document JSON (acteur, objet, collection, WebFinger).
     *
     * @param array{keyId: string, privateKey: string}|null $signer
     *
     * @return array<string, mixed>
     *
     * @throws FederationException
     */
    public function getJson(string $url, ?array $signer = null, string $accept = self::ACCEPT): array
    {
        $headers = ['Accept' => $accept, 'User-Agent' => 'Socialize (+ActivityPub)'];
        if (null !== $signer) {
            $headers += HttpSignature::sign('GET', $url, null, $signer['keyId'], $signer['privateKey']);
        }

        try {
            $response = $this->client->request('GET', $url, ['headers' => $headers, 'timeout' => 10, 'max_duration' => 20, 'max_redirects' => 3]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new FederationException(\sprintf('%s a répondu %d.', $url, $status), $status);
            }
            $length = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
            if ($length > self::MAX_BYTES) {
                throw new FederationException(\sprintf('%s : document trop volumineux.', $url));
            }
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new FederationException(\sprintf('%s est injoignable : %s', $url, $e->getMessage()), 0, $e);
        }

        if (\strlen($body) > self::MAX_BYTES) {
            throw new FederationException(\sprintf('%s : document trop volumineux.', $url));
        }
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new FederationException(\sprintf('%s ne renvoie pas de JSON valide.', $url));
        }

        return \App\Federation\Application\Json::keyed($data);
    }

    /**
     * Télécharge une image d'une autre instance (média d'une publication reçue), pour en fabriquer les copies ici.
     * Mêmes protections que getJson() ; refuse ce qui n'est pas une image ou dépasse $maxBytes.
     *
     * @throws FederationException
     */
    public function downloadImage(string $url, int $maxBytes): string
    {
        $tooLarge = static fn (): FederationException => new FederationException(\sprintf('%s : image trop volumineuse.', $url));
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => ['Accept' => 'image/*', 'User-Agent' => 'Socialize (+ActivityPub)'],
                'timeout' => 10,
                'max_duration' => 60,
                'max_redirects' => 3,
                // Arrêt dès que la taille est dépassée, même sans Content-Length.
                'on_progress' => static function (int $downloaded) use ($maxBytes, $tooLarge): void {
                    if ($downloaded > $maxBytes) {
                        throw $tooLarge();
                    }
                },
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new FederationException(\sprintf('%s a répondu %d.', $url, $status), $status);
            }
            $type = mb_strtolower((string) ($response->getHeaders(false)['content-type'][0] ?? ''));
            if (!str_starts_with($type, 'image/')) {
                throw new FederationException(\sprintf('%s n’est pas une image (%s).', $url, '' === $type ? 'type inconnu' : $type));
            }
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            if ($e->getPrevious() instanceof FederationException) {
                throw $e->getPrevious();
            }
            throw new FederationException(\sprintf('%s est injoignable : %s', $url, $e->getMessage()), 0, $e);
        }
        if (\strlen($body) > $maxBytes) {
            throw $tooLarge();
        }

        return $body;
    }

    /**
     * Lit le début d'une page web (aperçu d'un lien) : mêmes protections que getJson(), page HTML seulement, lecture
     * arrêtée après $maxBytes (l'en-tête, qui porte les balises Open Graph, est au début).
     *
     * @throws FederationException
     */
    public function fetchHtml(string $url, int $maxBytes): string
    {
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => ['Accept' => 'text/html,application/xhtml+xml', 'User-Agent' => 'Socialize (+link preview)'],
                'timeout' => 10,
                'max_duration' => 20,
                'max_redirects' => 5,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new FederationException(\sprintf('%s a répondu %d.', $url, $status), $status);
            }
            $type = mb_strtolower((string) ($response->getHeaders(false)['content-type'][0] ?? ''));
            if (!str_contains($type, 'html')) {
                throw new FederationException(\sprintf('%s n’est pas une page web (%s).', $url, '' === $type ? 'type inconnu' : $type));
            }
            $body = '';
            foreach ($this->client->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) >= $maxBytes) {
                    $response->cancel();
                    break;
                }
            }
        } catch (ExceptionInterface $e) {
            throw new FederationException(\sprintf('%s est injoignable : %s', $url, $e->getMessage()), 0, $e);
        }

        return substr($body, 0, $maxBytes);
    }

    /**
     * Livre une activité dans une boîte de réception.
     *
     * @param array{keyId: string, privateKey: string} $signer
     *
     * @return int code HTTP de la réponse
     *
     * @throws FederationException en cas d'erreur réseau
     */
    public function post(string $inbox, string $json, array $signer): int
    {
        $headers = ['Content-Type' => 'application/activity+json', 'Accept' => self::ACCEPT, 'User-Agent' => 'Socialize (+ActivityPub)']
            + HttpSignature::sign('POST', $inbox, $json, $signer['keyId'], $signer['privateKey']);

        try {
            return $this->client->request('POST', $inbox, ['headers' => $headers, 'body' => $json, 'timeout' => 15, 'max_duration' => 30])->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new FederationException(\sprintf('%s est injoignable : %s', $inbox, $e->getMessage()), 0, $e);
        }
    }
}
