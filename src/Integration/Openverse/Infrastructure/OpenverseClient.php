<?php

declare(strict_types=1);

namespace App\Integration\Openverse\Infrastructure;

use App\Integration\Openverse\Application\OpenverseException;
use App\Integration\Openverse\Application\OpenverseTrack;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API publique d'Openverse (https://api.openverse.org), sans compte : recherche de musique
 * sous licence libre et téléchargement du fichier chez son fournisseur (Jamendo, Freesound…).
 * Les recherches sont gardées en cache une heure pour ménager le quota anonyme.
 */
final readonly class OpenverseClient
{
    public const string API = 'https://api.openverse.org/v1/';
    public const int PAGE_SIZE = 20;
    /** Taille maximale d'un morceau téléchargé. */
    public const int MAX_BYTES = 30 * 1024 * 1024;

    public function __construct(
        #[Autowire(service: 'openverse.http_client')]
        private HttpClientInterface $client,
        private CacheInterface $openverseCache,
    ) {
    }

    /**
     * Musiques correspondant à la recherche, de la plus pertinente à la moins pertinente.
     *
     * @return array{tracks: list<OpenverseTrack>, next: ?int}
     */
    public function search(string $query, int $page = 1): array
    {
        $query = trim($query);
        $page = max(1, $page);
        if ('' === $query) {
            return ['tracks' => [], 'next' => null];
        }

        /** @var array{tracks: list<array<mixed>>, next: ?int} $cached */
        $cached = $this->openverseCache->get('openverse.search.'.hash('xxh128', mb_strtolower($query).'|'.$page), function (ItemInterface $item) use ($query, $page): array {
            $item->expiresAfter(3600);
            $data = $this->json('audio/', ['q' => $query, 'category' => 'music', 'page_size' => self::PAGE_SIZE, 'page' => $page]);
            $results = array_values(array_filter(\is_array($data['results'] ?? null) ? $data['results'] : [], \is_array(...)));
            $pageCount = is_numeric($data['page_count'] ?? null) ? (int) $data['page_count'] : $page;

            return ['tracks' => $results, 'next' => $page < $pageCount ? $page + 1 : null];
        });

        $tracks = [];
        foreach ($cached['tracks'] as $result) {
            $track = OpenverseTrack::fromApi($result);
            // Seuls les morceaux qu'on peut télécharger et assez longs pour un extrait.
            if (null !== $track->fileUrl && $track->duration >= 5) {
                $tracks[] = $track;
            }
        }

        return ['tracks' => $tracks, 'next' => $cached['next']];
    }

    public function track(string $id): OpenverseTrack
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            throw new OpenverseException('Morceau introuvable sur Openverse.');
        }

        return OpenverseTrack::fromApi($this->json('audio/'.strtolower($id).'/'));
    }

    /**
     * Télécharge le fichier du morceau dans un fichier, par flux, dans la limite de MAX_BYTES.
     */
    public function download(OpenverseTrack $track, string $target): void
    {
        $url = $track->fileUrl;
        if (null === $url || !\in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new OpenverseException('Ce morceau ne peut pas être téléchargé.');
        }

        $out = fopen($target, 'w') ?: throw new OpenverseException('Impossible d’écrire le morceau reçu.');
        try {
            $response = $this->client->request('GET', $url, ['timeout' => 30, 'max_duration' => 300, 'max_redirects' => 5]);
            if (200 !== $response->getStatusCode()) {
                throw new OpenverseException(\sprintf('Le fournisseur du morceau (%s) a répondu par une erreur (%d).', $track->provider, $response->getStatusCode()));
            }
            $size = 0;
            foreach ($this->client->stream($response) as $chunk) {
                $content = $chunk->getContent();
                $size += \strlen($content);
                if ($size > self::MAX_BYTES) {
                    $response->cancel();
                    throw new OpenverseException('Ce morceau est trop volumineux.');
                }
                fwrite($out, $content);
            }
        } catch (ExceptionInterface $e) {
            throw new OpenverseException(\sprintf('Le morceau n’a pas pu être téléchargé depuis %s.', $track->provider), $e->getCode(), previous: $e);
        } finally {
            fclose($out);
        }
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function json(string $path, array $query = []): array
    {
        try {
            $response = $this->client->request('GET', self::API.$path, ['query' => $query, 'timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
            $status = $response->getStatusCode();
            if (404 === $status) {
                throw new OpenverseException('Morceau introuvable sur Openverse.');
            }
            if (429 === $status) {
                throw new OpenverseException('Openverse limite le nombre de recherches : réessayez dans quelques minutes.');
            }
            if ($status >= 400) {
                throw new OpenverseException(\sprintf('Openverse a répondu par une erreur (%d).', $status));
            }
            $data = json_decode($response->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface $e) {
            throw new OpenverseException('Openverse est injoignable pour le moment.', $e->getCode(), previous: $e);
        } catch (\JsonException $e) {
            throw new OpenverseException('Réponse d’Openverse illisible.', $e->getCode(), previous: $e);
        }

        return \is_array($data) ? $data : [];
    }
}
