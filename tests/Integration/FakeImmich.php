<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Jcupitt\Vips\Image;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fausse instance Immich (v3) branchée sur le client HTTP d'Immich des tests : quatre médias (deux photos,
 * une vidéo, une photo live et sa courte vidéo, cachée et sans vignette comme dans Immich), un album, et l'API utilisée par Socialize. État statique : le noyau (donc ce service)
 * est recréé à chaque requête du client de test ; reset() au début de chaque test.
 */
final class FakeImmich
{
    public const string SERVER = 'http://immich.test';
    public const string KEY = 'cle-immich-de-test';
    public const string PHOTO_ETRETAT = '0199a1b2-0000-7000-8000-000000000001';
    public const string PHOTO_HONFLEUR = '0199a1b2-0000-7000-8000-000000000002';
    public const string VIDEO_LAC = '0199a1b2-0000-7000-8000-000000000003';
    public const string PHOTO_LIVE = '0199a1b2-0000-7000-8000-000000000004';
    public const string LIVE_MOTION = '0199a1b2-0000-7000-8000-000000000005';
    public const string ALBUM_NORMANDIE = '0199a1b2-0000-7000-8000-00000000a001';

    /** Serveur injoignable (erreur réseau). */
    public static bool $down = false;

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, mixed>, range: ?string}> */
    public static array $requests = [];

    /** @var array<string, array{name: string, assets: list<string>}> */
    public static array $albums = [];

    private static ?string $video = null;

    public static function reset(): void
    {
        self::$down = false;
        self::$requests = [];
        self::$albums = [self::ALBUM_NORMANDIE => ['name' => 'Normandie', 'assets' => [self::PHOTO_ETRETAT, self::PHOTO_HONFLEUR]]];
    }

    /**
     * @return list<array{method: string, path: string, body: array<string, mixed>, query: array<string, mixed>, range: ?string}>
     */
    public static function requestsTo(string $method, string $pathPattern): array
    {
        return array_values(array_filter(self::$requests, static fn (array $r): bool => $r['method'] === $method && (bool) preg_match($pathPattern, $r['path'])));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        if (self::$down) {
            return new MockResponse('', ['error' => 'Connexion refusée']);
        }
        $path = (string) preg_replace('#^/api#', '', (string) parse_url($url, \PHP_URL_PATH));
        $decoded = \is_string($options['body'] ?? null) ? json_decode($options['body'], true) : null;
        /** @var array<string, mixed> $body */
        $body = \is_array($decoded) ? $decoded : [];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $range = $this->header($options, 'range');
        self::$requests[] = ['method' => $method, 'path' => $path, 'body' => $body, 'query' => array_filter($query, \is_string(...), \ARRAY_FILTER_USE_KEY), 'range' => $range];

        if (!str_starts_with($url, self::SERVER.'/api/')) {
            return new MockResponse('', ['error' => 'Hôte inconnu']);
        }
        if (self::KEY !== $this->header($options, 'x-api-key')) {
            return $this->json(['message' => 'Invalid API key', 'statusCode' => 401], 401);
        }

        return match (true) {
            'GET' === $method && '/users/me' === $path => $this->json(['id' => 'u1', 'name' => 'Camille Test', 'email' => 'camille@immich.test']),
            'GET' === $method && '/server/version' === $path => $this->json(['major' => 3, 'minor' => 4, 'patch' => 1]),
            'GET' === $method && '/assets/statistics' === $path => $this->json(['images' => 2, 'videos' => 1, 'total' => 3]),
            'GET' === $method && '/albums' === $path => $this->json(array_map(
                static fn (string $id, array $a): array => ['id' => $id, 'albumName' => $a['name'], 'assetCount' => \count($a['assets'])],
                array_keys(self::$albums), self::$albums,
            )),
            'POST' === $method && '/albums' === $path => $this->createAlbum($body),
            'GET' === $method && (bool) preg_match('#^/albums/([^/]+)$#', $path, $m) => isset(self::$albums[$m[1]]) ? $this->json(['id' => $m[1], 'albumName' => self::$albums[$m[1]]['name']]) : $this->json(['message' => 'Not found'], 400),
            'PUT' === $method && (bool) preg_match('#^/albums/([^/]+)/assets$#', $path, $m) => $this->addToAlbum($m[1], $body),
            'POST' === $method && '/search/metadata' === $path => $this->search($body),
            'GET' === $method && (bool) preg_match('#^/assets/([^/]+)$#', $path, $m) && isset(self::assets()[$m[1]]) => $this->json(self::assets()[$m[1]]),
            'GET' === $method && (bool) preg_match('#^/assets/([^/]+)/thumbnail$#', $path, $m) && isset(self::assets()[$m[1]]) && 'hidden' !== self::assets()[$m[1]]['visibility'] => new MockResponse($this->jpeg(120, 120), ['response_headers' => ['content-type' => 'image/jpeg']]),
            // Vidéo lisible, par plages comme Immich.
            'GET' === $method && (bool) preg_match('#^/assets/([^/]+)/video/playback$#', $path, $m) && 'VIDEO' === (self::assets()[$m[1]]['type'] ?? null) => $this->playback($this->original($m[1]), $range),
            'GET' === $method && (bool) preg_match('#^/assets/([^/]+)/original$#', $path, $m) && isset(self::assets()[$m[1]]) => new MockResponse($this->original($m[1]), ['response_headers' => ['content-type' => 'application/octet-stream']]),
            default => $this->json(['message' => 'Not found'], 404),
        };
    }

    /**
     * @return array<string, array<string, mixed>> médias (AssetResponseDto), du plus récent au plus ancien
     */
    public static function assets(): array
    {
        return [
            self::PHOTO_HONFLEUR => self::asset(self::PHOTO_HONFLEUR, 'IMAGE', 'IMG_4204.jpg', 'image/jpeg', '2026-11-09T10:20:00.000Z', ['make' => 'Apple', 'model' => 'iPhone 16 Pro', 'latitude' => 49.4194, 'longitude' => 0.233, 'city' => 'Honfleur', 'country' => 'France', 'description' => 'Le Vieux Bassin']),
            self::PHOTO_ETRETAT => self::asset(self::PHOTO_ETRETAT, 'IMAGE', 'IMG_4200.jpg', 'image/jpeg', '2026-11-08T08:12:00.000Z', ['make' => 'FUJIFILM', 'model' => 'X-T5', 'lensModel' => 'XF16-55mm F2.8 R LM WR', 'fNumber' => 8, 'latitude' => 49.7072, 'longitude' => 0.2036, 'city' => 'Étretat', 'country' => 'France', 'description' => 'Lever de soleil sur la falaise']),
            // Photo live : la photo, puis sa courte vidéo (média caché), listées toutes deux par la recherche.
            self::PHOTO_LIVE => ['livePhotoVideoId' => self::LIVE_MOTION] + self::asset(self::PHOTO_LIVE, 'IMAGE', 'IMG_4300.HEIC', 'image/heic', '2026-10-20T15:00:00.000Z', ['make' => 'Apple', 'model' => 'iPhone 16 Pro', 'city' => 'Annecy', 'country' => 'France', 'description' => 'Le lac en mouvement']),
            self::LIVE_MOTION => ['visibility' => 'hidden'] + self::asset(self::LIVE_MOTION, 'VIDEO', 'IMG_4300.MOV', 'video/quicktime', '2026-10-20T15:00:00.000Z', [], 1500),
            self::VIDEO_LAC => self::asset(self::VIDEO_LAC, 'VIDEO', 'VID_7300.mp4', 'video/mp4', '2026-10-18T09:40:00.000Z', ['latitude' => 45.9812, 'longitude' => 6.8925, 'city' => 'Chamonix-Mont-Blanc', 'country' => 'France'], 4000),
        ];
    }

    /**
     * @param array<string, mixed> $exif
     *
     * @return array<string, mixed>
     */
    private static function asset(string $id, string $type, string $name, string $mime, string $taken, array $exif, int $duration = 0): array
    {
        return [
            'id' => $id, 'type' => $type, 'originalFileName' => $name, 'originalMimeType' => $mime,
            'localDateTime' => $taken, 'fileCreatedAt' => $taken, 'duration' => $duration, 'width' => 800, 'height' => 600, 'visibility' => 'timeline',
            'exifInfo' => $exif + ['dateTimeOriginal' => $taken, 'fileSizeInByte' => 120000],
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function search(array $query): MockResponse
    {
        $ids = array_keys(self::assets());
        $albumIds = \is_array($query['albumIds'] ?? null) ? $query['albumIds'] : [];
        if ([] !== $albumIds) {
            $ids = array_values(array_filter($ids, static fn (string $id): bool => array_any($albumIds, static fn (mixed $album): bool => \is_string($album) && \in_array($id, self::$albums[$album]['assets'] ?? [], true))));
        }
        if (\is_string($query['takenBefore'] ?? null)) {
            $before = new \DateTimeImmutable($query['takenBefore']);
            $ids = array_values(array_filter($ids, static fn (string $id): bool => \is_string($taken = self::assets()[$id]['localDateTime']) && new \DateTimeImmutable($taken) < $before));
        }
        $page = is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1;
        $items = 1 === $page ? array_map(static fn (string $id): array => self::assets()[$id], $ids) : [];

        return $this->json(['assets' => ['total' => \count($items), 'count' => \count($items), 'items' => $items, 'facets' => [], 'nextPage' => null, 'nextCursor' => null], 'albums' => ['total' => 0, 'count' => 0, 'items' => [], 'facets' => []]]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createAlbum(array $body): MockResponse
    {
        $id = \sprintf('0199a1b2-0000-7000-8000-%012d', \count(self::$albums) + 0xB000);
        self::$albums[$id] = ['name' => \is_string($body['albumName'] ?? null) ? $body['albumName'] : '', 'assets' => []];

        return $this->json(['id' => $id, 'albumName' => self::$albums[$id]['name']], 201);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addToAlbum(string $albumId, array $body): MockResponse
    {
        if (!isset(self::$albums[$albumId])) {
            return $this->json(['message' => 'Not found'], 400);
        }
        foreach (\is_array($body['ids'] ?? null) ? $body['ids'] : [] as $id) {
            self::$albums[$albumId]['assets'][] = \is_string($id) ? $id : '';
        }

        return $this->json([]);
    }

    private function playback(string $video, ?string $range): MockResponse
    {
        $size = \strlen($video);
        if (null !== $range && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $m)) {
            $to = '' === $m[2] ? $size - 1 : min((int) $m[2], $size - 1);
            $part = substr($video, (int) $m[1], $to - (int) $m[1] + 1);

            return new MockResponse($part, ['http_code' => 206, 'response_headers' => [
                'content-type' => 'video/mp4', 'accept-ranges' => 'bytes', 'content-length' => (string) \strlen($part), 'content-range' => \sprintf('bytes %d-%d/%d', $m[1], $to, $size),
            ]]);
        }

        return new MockResponse($video, ['response_headers' => ['content-type' => 'video/mp4', 'accept-ranges' => 'bytes', 'content-length' => (string) $size]]);
    }

    private function original(string $id): string
    {
        if (!\in_array($id, [self::VIDEO_LAC, self::LIVE_MOTION], true)) {
            return $this->jpeg(800, 600);
        }
        if (null === self::$video) {
            $path = sys_get_temp_dir().'/fake-immich-video.mp4';
            if (!is_file($path)) {
                exec(\sprintf('ffmpeg -v error -y -f lavfi -i testsrc=duration=1:size=320x240:rate=10 -c:v libx264 -pix_fmt yuv420p %s', escapeshellarg($path)));
            }
            self::$video = (string) file_get_contents($path);
        }

        return self::$video;
    }

    private function jpeg(int $width, int $height): string
    {
        return Image::black($width, $height, ['bands' => 3])->add([90, 140, 200])->cast('uchar')->writeToBuffer('.jpg', ['Q' => 80]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function header(array $options, string $name): ?string
    {
        $headers = \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
        $lines = $headers[$name] ?? [];
        $line = \is_array($lines) ? ($lines[0] ?? null) : null;

        return \is_string($line) ? trim(substr($line, \strlen($name) + 1)) : null;
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
