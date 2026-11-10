<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Jcupitt\Vips\Image;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Faux Openverse branché sur le client HTTP d'Openverse des tests : recherche, fiche d'un morceau,
 * et fichiers audio chez un faux fournisseur. État statique (le noyau est recréé à chaque requête
 * du client de test) ; reset() au début de chaque test.
 */
final class FakeOpenverse
{
    public const string PIANO = '0c0a8a9b-d85a-43c3-b847-cc7531f5b9ed';
    public const string ROCK = 'f783e2d8-640c-417f-86f2-102b3f475e7a';
    /** Résultat sans fichier téléchargeable : jamais proposé. */
    public const string NO_FILE = '5a5a5a5a-0000-4000-8000-000000000003';
    public const string FILES = 'https://files.fournisseur.test/';

    /** Openverse injoignable (erreur réseau). */
    public static bool $down = false;

    /** @var list<string> adresses demandées */
    public static array $requests = [];

    private static ?string $audio = null;

    public static function reset(): void
    {
        self::$down = false;
        self::$requests = [];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        if (\is_array($options['query'] ?? null) && [] !== $options['query'] && !str_contains($url, '?')) {
            $url .= '?'.http_build_query($options['query']);
        }
        self::$requests[] = $url;
        if (self::$down) {
            return new MockResponse('', ['error' => 'Connexion refusée']);
        }

        $path = (string) parse_url($url, \PHP_URL_PATH);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return match (true) {
            str_starts_with($url, self::FILES) => $this->file($this->header($options, 'range')),
            !str_starts_with($url, 'https://api.openverse.org/v1/') => new MockResponse('', ['error' => 'Hôte inconnu']),
            '/v1/audio/' === $path => $this->json(['result_count' => 3, 'page_count' => 1, 'page_size' => 20, 'page' => 1, 'results' => \is_string($query['q'] ?? null) && 'introuvable' !== $query['q'] ? array_values(self::tracks()) : []]),
            (bool) preg_match('#^/v1/audio/([0-9a-f-]{36})/$#', $path, $m) && isset(self::tracks()[$m[1]]) => $this->json(self::tracks()[$m[1]]),
            // Seul le morceau de piano a une pochette.
            '/v1/audio/'.self::PIANO.'/thumb/' === $path => new MockResponse(Image::black(64, 64, ['bands' => 3])->add([200, 120, 60])->cast('uchar')->writeToBuffer('.jpg'), ['response_headers' => ['content-type' => 'image/jpeg']]),
            default => $this->json(['detail' => 'Not found.'], 404),
        };
    }

    /**
     * @return array<string, array<string, mixed>> objets « audio » de l'API Openverse
     */
    public static function tracks(): array
    {
        return [
            self::PIANO => self::track(self::PIANO, 'Piano du matin', 'Lina Morel', 'by', '4.0', 75_000, ['classical', 'piano']),
            self::ROCK => ['thumbnail' => null] + self::track(self::ROCK, 'Route 66', 'The Testers', 'by-sa', '3.0', 200_000, ['rock']),
            self::NO_FILE => ['url' => null] + self::track(self::NO_FILE, 'Sans fichier', 'Personne', 'cc0', '1.0', 90_000, []),
        ];
    }

    /**
     * @param list<string> $genres
     *
     * @return array<string, mixed>
     */
    private static function track(string $id, string $title, string $artist, string $license, string $version, int $durationMs, array $genres): array
    {
        return [
            'id' => $id, 'title' => $title, 'creator' => $artist,
            'license' => $license, 'license_version' => $version, 'license_url' => \sprintf('https://creativecommons.org/licenses/%s/%s/', $license, $version),
            'foreign_landing_url' => 'https://www.jamendo.com/track/'.substr($id, 0, 8),
            'url' => self::FILES.$id.'.m4a', 'provider' => 'jamendo', 'source' => 'jamendo', 'category' => 'music',
            'genres' => $genres, 'duration' => $durationMs, 'filetype' => 'm4a', 'mature' => false,
            'thumbnail' => 'https://api.openverse.org/v1/audio/'.$id.'/thumb/',
        ];
    }

    /**
     * Morceau de 40 s (sinusoïde), fabriqué une fois par processus.
     */
    private function audio(): string
    {
        if (null === self::$audio) {
            $path = sys_get_temp_dir().'/'.uniqid('socialize-music-', true).'.m4a';
            exec(\sprintf('ffmpeg -v error -y -f lavfi -i sine=frequency=440:duration=40 -c:a aac -b:a 32k %s', escapeshellarg($path)), $output, $code);
            if (0 !== $code) {
                throw new \RuntimeException('ffmpeg doit être installé pour les tests de musique.');
            }
            self::$audio = (string) file_get_contents($path);
            unlink($path);
        }

        return self::$audio;
    }

    /**
     * Fichier audio du faux fournisseur, en entier ou par plage (requête partielle, comme Jamendo).
     */
    private function file(?string $range): MockResponse
    {
        $audio = $this->audio();
        $size = \strlen($audio);
        if (null !== $range && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $m)) {
            $from = (int) $m[1];
            $to = '' === $m[2] ? $size - 1 : min((int) $m[2], $size - 1);

            return new MockResponse(substr($audio, $from, $to - $from + 1), ['http_code' => 206, 'response_headers' => [
                'content-type' => 'audio/mp4', 'accept-ranges' => 'bytes',
                'content-length' => (string) ($to - $from + 1), 'content-range' => \sprintf('bytes %d-%d/%d', $from, $to, $size),
            ]]);
        }

        return new MockResponse($audio, ['response_headers' => ['content-type' => 'audio/mp4', 'content-length' => (string) $size, 'accept-ranges' => 'bytes']]);
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
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
