<?php

declare(strict_types=1);

namespace App\Integration\SynologyPhotos\Infrastructure;

use App\Integration\SynologyPhotos\Application\SynologyPhotosException;
use App\Integration\SynologyPhotos\Application\SynologyPhotosItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API web de Synology Photos (DSM 7, /webapi/entry.cgi), avec une session DSM ouverte une fois par le compte.
 *
 * Cette API n'est pas documentée par Synology : les appels ci-dessous ont été vérifiés sur un NAS réel
 * (SYNO.API.Auth v6, SYNO.Foto.* et SYNO.FotoTeam.* v2 à v4). L'identifiant de session part toujours dans le corps
 * de la requête, jamais dans l'adresse. Le NAS est souvent sur le réseau local : les adresses privées sont permises.
 */
final readonly class SynologyPhotosClient
{
    public const int PAGE_SIZE = 60;

    /** Session absente, expirée ou coupée (codes d'erreur communs de DSM). */
    private const array SESSION_ERRORS = [105, 106, 107, 119];

    public function __construct(
        #[Autowire(service: 'synology_photos.http_client')]
        private HttpClientInterface $client,
    ) {
    }

    /**
     * Ouvre une session DSM et rend son identifiant. Le mot de passe n'est utilisé qu'ici.
     */
    public function login(string $server, string $user, #[\SensitiveParameter] string $password, ?string $otp = null): string
    {
        $fields = ['api' => 'SYNO.API.Auth', 'version' => '6', 'method' => 'login', 'account' => $user, 'passwd' => $password, 'format' => 'sid', 'session' => 'Socialize'];
        if (null !== $otp && '' !== trim($otp)) {
            $fields['otp_code'] = trim($otp);
        }
        $result = $this->decode($server, $this->send($server, $fields));
        if (true !== ($result['success'] ?? null)) {
            throw new SynologyPhotosException(match ($this->errorCode($result)) {
                400 => 'Identifiant ou mot de passe refusé par le NAS.', 401 => 'Ce compte est désactivé sur le NAS.', 402 => 'Ce compte n’a pas le droit de se connecter au NAS.', 403 => 'Ce compte utilise la double authentification : saisissez aussi le code à usage unique.', 404 => 'Code à usage unique refusé par le NAS.', 407 => 'Le NAS a bloqué cette adresse après trop d’essais : réessayez plus tard.', 408, 409, 410 => 'Le mot de passe de ce compte doit être changé sur le NAS.', default => \sprintf('Le NAS a refusé la connexion (erreur %d).', $this->errorCode($result)),
            });
        }
        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];

        return \is_string($data['sid'] ?? null) && '' !== $data['sid'] ? $data['sid'] : throw new SynologyPhotosException('Le NAS n’a pas ouvert de session.');
    }

    /**
     * Ferme la session sur le NAS (à la déconnexion du compte).
     */
    public function logout(string $server, string $session): void
    {
        $this->send($server, ['api' => 'SYNO.API.Auth', 'version' => '6', 'method' => 'logout', '_sid' => $session])->getStatusCode();
    }

    /**
     * Test de la session : compte Photos et contenu des deux espaces (l'espace partagé peut être désactivé).
     *
     * @return array{user: string, personal: int, team: ?int, albums: int}
     */
    public function check(string $server, string $session): array
    {
        $me = $this->call($server, $session, 'SYNO.Foto.UserInfo', 1, 'me');
        $personal = $this->call($server, $session, 'SYNO.Foto.Browse.Item', 4, 'count');
        try {
            $team = $this->int($this->call($server, $session, 'SYNO.FotoTeam.Browse.Item', 4, 'count'), 'count');
        } catch (SynologyPhotosException $e) {
            if ($e->sessionExpired) {
                throw $e;
            }
            $team = null;
        }

        return [
            'user' => \is_string($me['name'] ?? null) ? $me['name'] : '',
            'personal' => $this->int($personal, 'count'),
            'team' => $team,
            'albums' => \count($this->albums($server, $session)),
        ];
    }

    /**
     * Photothèque, de la plus récente à la plus ancienne : un espace, ou un album (qui peut mêler les deux espaces),
     * éventuellement limitée aux médias pris avant une date.
     *
     * @param SynologyPhotosItem::PERSONAL|SynologyPhotosItem::TEAM $space
     *
     * @return array{items: list<SynologyPhotosItem>, next: ?int}
     */
    public function library(string $server, string $session, string $space, int $page, ?int $albumId = null, ?\DateTimeImmutable $takenBefore = null): array
    {
        $page = max(1, $page);
        $params = [
            'offset' => (string) (($page - 1) * self::PAGE_SIZE),
            'limit' => (string) self::PAGE_SIZE,
            'sort_by' => 'takentime',
            'sort_direction' => 'desc',
            'additional' => '["thumbnail","video_meta","video_convert","address"]',
        ];
        if (null !== $albumId) {
            // Les albums se lisent par l'API de l'espace personnel, quel que soit l'espace de leurs médias.
            $space = SynologyPhotosItem::PERSONAL;
            $params['album_id'] = (string) $albumId;
        } elseif ($takenBefore instanceof \DateTimeImmutable) {
            $params['end_time'] = (string) ($takenBefore->getTimestamp() - 1);
        }
        $list = $this->list($this->call($server, $session, $this->api($space, 'Browse.Item'), 4, 'list', $params));

        return [
            'items' => array_map(static fn (array $item): SynologyPhotosItem => SynologyPhotosItem::fromApi($item, $space), $list),
            'next' => \count($list) >= self::PAGE_SIZE ? $page + 1 : null,
        ];
    }

    /**
     * @return list<array{id: int, name: string, count: int}>
     */
    public function albums(string $server, string $session): array
    {
        $albums = [];
        foreach ($this->list($this->call($server, $session, 'SYNO.Foto.Browse.Album', 2, 'list', ['offset' => '0', 'limit' => '500'])) as $album) {
            if (is_numeric($album['id'] ?? null)) {
                $albums[] = ['id' => (int) $album['id'], 'name' => \is_string($album['name'] ?? null) ? $album['name'] : '', 'count' => $this->int($album, 'item_count')];
            }
        }
        usort($albums, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $albums;
    }

    /**
     * @param SynologyPhotosItem::PERSONAL|SynologyPhotosItem::TEAM $space
     */
    public function item(string $server, string $session, string $space, int $id): SynologyPhotosItem
    {
        $list = $this->list($this->call($server, $session, $this->api($space, 'Browse.Item'), 4, 'get', [
            'id' => \sprintf('[%d]', $id),
            'additional' => '["thumbnail","video_meta","exif","gps","address"]',
        ]));

        return isset($list[0]) ? SynologyPhotosItem::fromApi($list[0], $space) : throw new SynologyPhotosException('Élément introuvable dans Synology Photos.');
    }

    /**
     * Vignette d'un média, à relayer telle quelle (la session reste sur le serveur). Chaque espace a son API.
     * La clé de cache est « <unité>_<date> » : l'API attend l'identifiant de l'unité, pas celui de l'élément.
     *
     * @param SynologyPhotosItem::PERSONAL|SynologyPhotosItem::TEAM $space
     */
    public function thumbnail(string $server, string $session, string $space, string $cacheKey, string $size = 'sm'): ResponseInterface
    {
        return $this->binary($server, $session, [
            'api' => $this->api($space, 'Thumbnail'), 'version' => '2', 'method' => 'get', 'id' => strstr($cacheKey, '_', true) ?: '0', 'cache_key' => $cacheKey, 'type' => 'unit', 'size' => $size,
        ]);
    }

    /**
     * Version convertie d'une vidéo (voir SynologyPhotosItem::STREAM_QUALITIES), à relayer telle quelle. Chaque
     * espace a son API ; « type=item » désigne l'élément lui-même (« unit » attendrait l'identifiant d'unité).
     *
     * @param SynologyPhotosItem::PERSONAL|SynologyPhotosItem::TEAM $space
     * @param array<string, string>                                 $headers en-têtes à transmettre (plage demandée)
     */
    public function stream(string $server, string $session, string $space, int $id, string $quality, array $headers = []): ResponseInterface
    {
        return $this->binary($server, $session, [
            'api' => $this->api($space, 'Streaming'), 'version' => '2', 'method' => 'streaming', 'id' => (string) $id, 'type' => 'item', 'quality' => $quality,
        ], ['headers' => $headers, 'timeout' => 60, 'max_duration' => 3600]);
    }

    /**
     * @return iterable<string>
     */
    public function chunks(ResponseInterface $response): iterable
    {
        try {
            foreach ($this->client->stream($response) as $chunk) {
                yield $chunk->getContent();
            }
        } catch (ExceptionInterface $e) {
            throw new SynologyPhotosException('La lecture depuis Synology Photos a été interrompue.', previous: $e);
        }
    }

    /**
     * Télécharge l'original d'un média dans un fichier, par flux. Pour un média à plusieurs fichiers (Live Photo de
     * l'iPhone : photo et courte vidéo ; photo et ses variantes), Synology Photos envoie une archive ZIP : le fichier
     * du média en est extrait (celui qui porte son nom, sinon la plus grande photo, ou vidéo).
     *
     * @param SynologyPhotosItem::PERSONAL|SynologyPhotosItem::TEAM $space
     */
    public function download(string $server, string $session, string $space, int $id, string $target, string $fileName = '', bool $video = false, int $maxBytes = \PHP_INT_MAX): void
    {
        $response = $this->binary($server, $session, [
            'api' => $this->api($space, 'Download'), 'version' => '2', 'method' => 'download', 'item_id' => \sprintf('[%d]', $id), 'force_download' => 'true',
        ], ['timeout' => 120, 'max_duration' => 1800]);
        $out = fopen($target, 'w') ?: throw new SynologyPhotosException('Impossible d’écrire le fichier reçu de Synology Photos.');
        try {
            foreach ($this->chunks($response) as $chunk) {
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }
        if ("PK\x03\x04" === file_get_contents($target, false, null, 0, 4)) {
            $this->extract($target, $fileName, $video, $maxBytes);
        }
    }

    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'heic', 'heif', 'png', 'webp', 'gif', 'tif', 'tiff', 'avif', 'dng'];
    private const array VIDEO_EXTENSIONS = ['mov', 'mp4', 'm4v', '3gp', 'avi', 'mkv', 'webm', 'mts', 'm2ts'];

    /**
     * Remplace l'archive par le fichier du média qu'elle contient.
     */
    private function extract(string $archive, string $fileName, bool $video, int $maxBytes): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive, \ZipArchive::RDONLY)) {
            throw new SynologyPhotosException('Synology Photos a envoyé une archive illisible.');
        }
        try {
            $wanted = $video ? self::VIDEO_EXTENSIONS : self::IMAGE_EXTENSIONS;
            $best = null;
            $bestSize = -1;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if (false === $stat || str_ends_with($stat['name'], '/')) {
                    continue;
                }
                $base = basename($stat['name']);
                if ('' !== $fileName && 0 === strcasecmp($base, $fileName)) {
                    $best = $i;
                    break;
                }
                if (\in_array(strtolower(pathinfo($base, \PATHINFO_EXTENSION)), $wanted, true) && $stat['size'] > $bestSize) {
                    $best = $i;
                    $bestSize = $stat['size'];
                }
            }
            $stat = null !== $best ? $zip->statIndex($best) : false;
            if (false === $stat) {
                throw new SynologyPhotosException(\sprintf('L’archive envoyée par Synology Photos ne contient pas de %s.', $video ? 'vidéo' : 'photo'));
            }
            if ($stat['size'] > $maxBytes) {
                throw new SynologyPhotosException(\sprintf('« %s » dépasse la taille maximale.', basename($stat['name'])));
            }
            $in = $zip->getStream($stat['name']) ?: throw new SynologyPhotosException('Synology Photos a envoyé une archive illisible.');
            $extracted = $archive.'.extrait';
            $out = fopen($extracted, 'w') ?: throw new SynologyPhotosException('Impossible d’écrire le fichier reçu de Synology Photos.');
            try {
                stream_copy_to_stream($in, $out, $maxBytes + 1);
            } finally {
                fclose($out);
                fclose($in);
            }
        } finally {
            $zip->close();
        }
        rename($extracted, $archive);
    }

    /**
     * @param SynologyPhotosItem::PERSONAL|SynologyPhotosItem::TEAM $space
     */
    private function api(string $space, string $name): string
    {
        return (SynologyPhotosItem::TEAM === $space ? 'SYNO.FotoTeam.' : 'SYNO.Foto.').$name;
    }

    /**
     * Appel JSON authentifié ; rend le contenu de « data ».
     *
     * @param array<string, string> $params
     *
     * @return array<mixed>
     */
    private function call(string $server, string $session, string $api, int $version, string $method, array $params = []): array
    {
        $result = $this->decode($server, $this->send($server, ['api' => $api, 'version' => (string) $version, 'method' => $method, '_sid' => $session] + $params));
        if (true !== ($result['success'] ?? null)) {
            throw $this->failure($this->errorCode($result));
        }

        return \is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /**
     * Appel dont la réponse est un fichier ; une erreur arrive en JSON, avec un statut 200.
     *
     * @param array<string, string> $fields
     * @param array<string, mixed>  $options
     */
    private function binary(string $server, string $session, array $fields, array $options = []): ResponseInterface
    {
        $response = $this->send($server, $fields + ['_sid' => $session], $options);
        try {
            $type = $response->getHeaders(false)['content-type'][0] ?? '';
        } catch (ExceptionInterface $e) {
            throw new SynologyPhotosException(\sprintf('Le NAS est injoignable à l’adresse %s.', $server), previous: $e);
        }
        if (str_contains($type, 'json')) {
            throw $this->failure($this->errorCode($this->decode($server, $response)));
        }

        return $response;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed>  $options
     */
    private function send(string $server, array $fields, array $options = []): ResponseInterface
    {
        try {
            $response = $this->client->request('POST', rtrim($server, '/').'/webapi/entry.cgi', array_replace(['timeout' => 15], $options, ['body' => $fields]));
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new SynologyPhotosException(\sprintf('Le NAS est injoignable à l’adresse %s.', $server), previous: $e);
        }
        if ($status >= 400) {
            throw new SynologyPhotosException(\sprintf('Le NAS a répondu par une erreur (%d) : l’adresse est-elle bien celle de DSM ou de Synology Photos ?', $status));
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $server, ResponseInterface $response): array
    {
        try {
            $data = json_decode($response->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new SynologyPhotosException(\sprintf('Réponse illisible : %s est-elle bien l’adresse de DSM ou de Synology Photos ?', $server), previous: $e);
        }

        return \is_array($data) ? $data : [];
    }

    private function failure(int $code): SynologyPhotosException
    {
        return \in_array($code, self::SESSION_ERRORS, true)
            ? new SynologyPhotosException('La session Synology Photos a expiré : reconnectez-vous dans Réglages › Synology Photos.', sessionExpired: true)
            : new SynologyPhotosException(\sprintf('Synology Photos a répondu par une erreur (%d).', $code));
    }

    /**
     * @param array<mixed> $result
     */
    private function errorCode(array $result): int
    {
        $error = \is_array($result['error'] ?? null) ? $result['error'] : [];

        return is_numeric($error['code'] ?? null) ? (int) $error['code'] : 0;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    private function list(array $data): array
    {
        return array_values(array_filter(\is_array($data['list'] ?? null) ? $data['list'] : [], \is_array(...)));
    }

    /**
     * @param array<mixed> $data
     */
    private function int(array $data, string $key): int
    {
        return is_numeric($data[$key] ?? null) ? (int) $data[$key] : 0;
    }
}
