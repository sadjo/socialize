<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Jcupitt\Vips\Image;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Faux NAS Synology (DSM 7, Synology Photos) branché sur le client HTTP des tests : connexion par session,
 * espace personnel, espace partagé, albums, vignettes et originaux. Les réponses reprennent la forme relevée sur
 * un NAS réel (erreurs en JSON avec un statut 200, code 119 pour une session inconnue). État statique (le noyau
 * est recréé à chaque requête du client de test) ; reset() au début de chaque test.
 */
final class FakeSynologyPhotos
{
    public const string SERVER = 'https://photos.nas.test';
    public const string USER = 'camille';
    public const string PASSWORD = 'mot-de-passe-du-nas';
    public const string OTP = '123456';

    public const int PHOTO_PLAGE = 501;
    public const int VIDEO_PORT = 502;
    public const int PHOTO_ANCIENNE = 503;
    /** Espace partagé. */
    public const int PHOTO_FAMILLE = 601;
    public const int VIDEO_ANNIVERSAIRE = 602;
    public const int ALBUM_VACANCES = 7;
    /** Album de 61 photos : une page pleine (60), puis une seconde page d'une photo. */
    public const int ALBUM_LONG = 8;
    /** Écart entre l'identifiant d'un élément et celui de son unité (clé de cache des vignettes). */
    public const int UNIT_OFFSET = 9000;

    /** NAS injoignable (erreur réseau). */
    public static bool $down = false;
    /** Le compte DSM utilise la double authentification. */
    public static bool $otpRequired = false;

    /** @var list<string> sessions ouvertes */
    public static array $sessions = [];

    /** @var list<array<string, string>> champs des requêtes reçues */
    public static array $requests = [];

    private static ?string $video = null;

    public static function reset(): void
    {
        self::$down = false;
        self::$otpRequired = false;
        self::$sessions = [];
        self::$requests = [];
    }

    /**
     * Le NAS oublie toutes les sessions (expiration, redémarrage).
     */
    public static function expireSessions(): void
    {
        self::$sessions = [];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function requestsTo(string $api, string $method): array
    {
        return array_values(array_filter(self::$requests, static fn (array $r): bool => ($r['api'] ?? '') === $api && ($r['method'] ?? '') === $method));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        if (self::$down) {
            return new MockResponse('', ['error' => 'Connexion refusée']);
        }
        parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $parsed);
        /** @var array<string, string> $fields */
        $fields = array_filter($parsed, \is_string(...));
        self::$requests[] = $fields;

        if ('POST' !== $method || self::SERVER.'/webapi/entry.cgi' !== $url) {
            return new MockResponse('<html>Not found</html>', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']]);
        }
        $api = $fields['api'] ?? '';
        $call = $fields['method'] ?? '';
        if ('SYNO.API.Auth' === $api) {
            return 'login' === $call ? $this->login($fields) : $this->logout($fields);
        }
        if (!\in_array($fields['_sid'] ?? '', self::$sessions, true)) {
            return $this->error(119);
        }
        $team = str_starts_with($api, 'SYNO.FotoTeam.');
        // « id » est un entier (vignette) ou une liste JSON (« [501] ») ; « item_id » une liste JSON.
        $raw = $fields['id'] ?? $fields['item_id'] ?? '';
        $decoded = json_decode($raw, true);
        $id = \is_array($decoded) ? (is_numeric($decoded[0] ?? null) ? (int) $decoded[0] : 0) : (int) $raw;

        return match (true) {
            'SYNO.Foto.UserInfo' === $api && 'me' === $call => $this->json(['id' => 1, 'name' => self::USER, 'enabled' => true]),
            \in_array($api, ['SYNO.Foto.Browse.Item', 'SYNO.FotoTeam.Browse.Item'], true) && 'count' === $call => $this->json(['count' => \count(self::items($team))]),
            \in_array($api, ['SYNO.Foto.Browse.Item', 'SYNO.FotoTeam.Browse.Item'], true) && 'list' === $call => $this->list($team, $fields),
            \in_array($api, ['SYNO.Foto.Browse.Item', 'SYNO.FotoTeam.Browse.Item'], true) && 'get' === $call => $this->json(['list' => isset(self::items($team)[$id]) ? [self::items($team)[$id]] : []]),
            'SYNO.Foto.Browse.Album' === $api && 'list' === $call => $this->json(['list' => [['id' => self::ALBUM_VACANCES, 'name' => 'Vacances', 'item_count' => 2, 'type' => 'normal', 'shared' => false]]]),
            // Vignettes : l'API de l'espace du média, avec l'identifiant de l'unité (préfixe de la clé de cache),
            // qui n'est pas celui de l'élément.
            \in_array($api, ['SYNO.Foto.Thumbnail', 'SYNO.FotoTeam.Thumbnail'], true) && 'get' === $call && isset(self::items($team)[$id - self::UNIT_OFFSET]) && ($fields['cache_key'] ?? '') === $id.'_1700000000' => new MockResponse($this->jpeg(120, 160), ['response_headers' => ['content-type' => 'image/jpeg']]),
            \in_array($api, ['SYNO.Foto.Thumbnail', 'SYNO.FotoTeam.Thumbnail'], true) => new MockResponse('<html>Not found</html>', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']]),
            // Lecture : l'API de l'espace, « type=item », et une qualité parmi les conversions de la vidéo.
            \in_array($api, ['SYNO.Foto.Streaming', 'SYNO.FotoTeam.Streaming'], true) && 'streaming' === $call && 'item' === ($fields['type'] ?? '')
                && \in_array($fields['quality'] ?? '', $this->qualities($team, $id), true) => $this->playback($this->original($id), $this->header($options, 'range')),
            \in_array($api, ['SYNO.Foto.Streaming', 'SYNO.FotoTeam.Streaming'], true) => new MockResponse('<html>Not found</html>', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']]),
            \in_array($api, ['SYNO.Foto.Download', 'SYNO.FotoTeam.Download'], true) && 'download' === $call && isset(self::items($team)[$id]) => new MockResponse($this->original($id), ['response_headers' => ['content-type' => 'application/force-download']]),
            \in_array($api, ['SYNO.Foto.Download', 'SYNO.FotoTeam.Download'], true) => $this->error(117),
            default => $this->error(102),
        };
    }

    /**
     * @return array<int, array<string, mixed>> éléments d'un espace, du plus récent au plus ancien
     */
    public static function items(bool $team): array
    {
        return $team ? [
            self::PHOTO_FAMILLE => self::item(self::PHOTO_FAMILLE, 'photo', 'Repas de famille.JPG', '2026-08-15 13:05:00', 0),
            // Vidéo encore sans version lisible (seulement « flv ») : pas de lecture dans l'aperçu.
            self::VIDEO_ANNIVERSAIRE => self::item(self::VIDEO_ANNIVERSAIRE, 'video', 'Anniversaire.AVI', '2026-06-01 18:00:00', 0, duration: 3000, converted: ['flv']),
        ] : [
            self::PHOTO_PLAGE => self::item(self::PHOTO_PLAGE, 'photo', 'IMG_5001.jpg', '2026-11-09 17:30:00', 1, ['camera' => 'iPhone 16 Pro', 'lens' => 'iPhone 16 Pro back camera 6.86mm f/1.78'], ['latitude' => 48.6493, 'longitude' => -2.0257], ['city' => 'Saint-Malo', 'country' => 'France']),
            self::VIDEO_PORT => self::item(self::VIDEO_PORT, 'video', 'VID_5002.mp4', '2026-11-08 09:10:00', 1, duration: 4000, converted: ['high', 'flv']),
            self::PHOTO_ANCIENNE => self::item(self::PHOTO_ANCIENNE, 'photo', 'IMG_0003.jpg', '2025-05-01 12:00:00', 1),
        ];
    }

    /**
     * @param array<string, string> $exif
     * @param array<string, float>  $gps
     * @param array<string, string> $address
     * @param list<string>          $converted versions converties d'une vidéo (« video_convert »)
     *
     * @return array<string, mixed>
     */
    private static function item(int $id, string $type, string $name, string $taken, int $owner, array $exif = [], array $gps = [], array $address = [], int $duration = 0, array $converted = []): array
    {
        return [
            'id' => $id, 'filename' => $name, 'filesize' => 120000, 'type' => $type, 'owner_user_id' => $owner, 'folder_id' => 12,
            // Heure locale exprimée en UTC, comme sur un NAS réel.
            'time' => new \DateTimeImmutable($taken, new \DateTimeZone('UTC'))->getTimestamp(),
            'additional' => [
                'thumbnail' => ['sm' => 'ready', 'm' => 'ready', 'xl' => 'ready', 'cache_key' => ($id + self::UNIT_OFFSET).'_1700000000', 'unit_id' => $id + self::UNIT_OFFSET],
                'exif' => $exif + ['aperture' => '', 'camera' => '', 'exposure_time' => '', 'focal_length' => '', 'iso' => '', 'lens' => ''],
                'gps' => [] !== $gps ? $gps : null,
                'address' => [] !== $address ? $address : null,
            ] + ($duration > 0 ? ['video_meta' => ['duration' => $duration], 'video_convert' => array_map(static fn (string $q): array => ['quality' => $q, 'metadata' => []], $converted)] : []),
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    private function login(array $fields): MockResponse
    {
        if (self::USER !== ($fields['account'] ?? '') || self::PASSWORD !== ($fields['passwd'] ?? '')) {
            return $this->error(400);
        }
        if (self::$otpRequired && !isset($fields['otp_code'])) {
            return $this->error(403);
        }
        if (self::$otpRequired && self::OTP !== $fields['otp_code']) {
            return $this->error(404);
        }
        $sid = 'sid-'.bin2hex(random_bytes(12));
        self::$sessions[] = $sid;

        return $this->json(['did' => 'appareil', 'is_portal_port' => false, 'sid' => $sid]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function logout(array $fields): MockResponse
    {
        self::$sessions = array_values(array_filter(self::$sessions, static fn (string $sid): bool => $sid !== ($fields['_sid'] ?? '')));

        return new MockResponse(json_encode(['success' => true], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json; charset="UTF-8"']]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function list(bool $team, array $fields): MockResponse
    {
        $items = array_values(self::items($team));
        if (isset($fields['album_id'])) {
            // Un album peut mêler les deux espaces ; il se lit par l'API de l'espace personnel.
            $items = match (true) {
                $team => [],
                self::ALBUM_VACANCES === (int) $fields['album_id'] => [self::items(false)[self::PHOTO_PLAGE], self::items(true)[self::PHOTO_FAMILLE]],
                self::ALBUM_LONG === (int) $fields['album_id'] => array_map(static fn (int $i): array => self::item(1000 + $i, 'photo', \sprintf('IMG_%04d.jpg', $i), '2024-01-01 12:00:00', 1), range(1, 61)),
                default => [],
            };
        }
        if (isset($fields['end_time'])) {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['time'] <= (int) $fields['end_time']));
        }

        return $this->json(['list' => \array_slice($items, (int) ($fields['offset'] ?? 0), (int) ($fields['limit'] ?? 100))]);
    }

    /**
     * @return list<mixed> versions converties d'une vidéo
     */
    private function qualities(bool $team, int $id): array
    {
        $additional = self::items($team)[$id]['additional'] ?? null;
        $converted = \is_array($additional) && \is_array($additional['video_convert'] ?? null) ? $additional['video_convert'] : [];

        return array_values(array_map(static fn (mixed $c): mixed => \is_array($c) ? ($c['quality'] ?? null) : null, $converted));
    }

    private function playback(string $video, ?string $range): MockResponse
    {
        $size = \strlen($video);
        if (null !== $range && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $m)) {
            $to = '' === $m[2] ? $size - 1 : min((int) $m[2], $size - 1);
            $part = substr($video, (int) $m[1], $to - (int) $m[1] + 1);

            return new MockResponse($part, ['http_code' => 206, 'response_headers' => [
                'content-type' => 'video/mp4', 'content-length' => (string) \strlen($part), 'content-range' => \sprintf('bytes %d-%d/%d', $m[1], $to, $size),
            ]]);
        }

        return new MockResponse($video, ['response_headers' => ['content-type' => 'video/mp4', 'content-length' => (string) $size]]);
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

    private function original(int $id): string
    {
        if (self::VIDEO_PORT !== $id) {
            return $this->jpeg(800, 600);
        }
        if (null === self::$video) {
            $path = sys_get_temp_dir().'/fake-synology-video.mp4';
            if (!is_file($path)) {
                exec(\sprintf('ffmpeg -v error -y -f lavfi -i testsrc=duration=1:size=320x240:rate=10 -c:v libx264 -pix_fmt yuv420p %s', escapeshellarg($path)));
            }
            self::$video = (string) file_get_contents($path);
        }

        return self::$video;
    }

    private function jpeg(int $width, int $height): string
    {
        return Image::black($width, $height, ['bands' => 3])->add([200, 150, 90])->cast('uchar')->writeToBuffer('.jpg', ['Q' => 80]);
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse(json_encode(['success' => true, 'data' => $data], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json; charset="UTF-8"']]);
    }

    private function error(int $code): MockResponse
    {
        return new MockResponse(json_encode(['error' => ['code' => $code], 'success' => false], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json; charset="UTF-8"']]);
    }
}
