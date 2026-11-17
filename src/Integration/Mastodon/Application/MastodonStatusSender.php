<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Core\Application\Post\Mentions;
use App\Integration\Mastodon\Infrastructure\MastodonClient;
use Symfony\Component\Clock\ClockInterface;

/**
 * Publie un message avec ses médias sur un serveur Mastodon : chaque média, attendu jusqu'à ce que le serveur l'ait
 * traité (une vidéo est convertie en différé), puis le message.
 */
final readonly class MastodonStatusSender
{
    /** Attente du traitement des médias par le serveur : 90 essais, toutes les 2 secondes (3 minutes). */
    private const int READY_ATTEMPTS = 90;
    private const int READY_INTERVAL = 2;

    public function __construct(
        private MastodonClient $client,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{path: string, mimeType: string, alt?: ?string}> $media
     * @param 'public'|'unlisted'|'private'|'direct'                     $visibility
     *
     * @return array{id: string, uri: string}
     *
     * @throws MastodonException
     */
    public function send(string $domain, #[\SensitiveParameter] string $token, array $media, string $text, string $visibility, string $idempotencyKey, ?string $inReplyTo = null, ?string $spoiler = null): array
    {
        $ids = [];
        foreach ($media as $file) {
            $uploaded = $this->client->uploadMedia($domain, $token, $file['path'], $file['mimeType'], $file['alt'] ?? null);
            $ids[] = $uploaded['id'];
            if (!$uploaded['ready']) {
                $this->waitFor($domain, $token, $uploaded['id']);
            }
        }

        return $this->client->postStatus($domain, $token, self::forMastodon($text), $inReplyTo, $visibility, $idempotencyKey, $ids, $spoiler);
    }

    private function waitFor(string $domain, string $token, string $mediaId): void
    {
        for ($attempt = 0; $attempt < self::READY_ATTEMPTS; ++$attempt) {
            $this->clock->sleep(self::READY_INTERVAL);
            if ($this->client->mediaReady($domain, $token, $mediaId)) {
                return;
            }
        }
        throw new MastodonException(\sprintf('%s n’a pas fini de traiter un média : publiez de nouveau un peu plus tard.', $domain));
    }

    /**
     * « ig:@nom » (compte Instagram) devient l'adresse de son profil : laissé tel quel, Mastodon y verrait une mention
     * de « @nom » sur son propre serveur.
     */
    public static function forMastodon(string $text): string
    {
        return (string) preg_replace_callback(Mentions::PATTERN, static fn (array $m): string => null !== $m[1]
            ? Mentions::instagramUrl($m[2])
            : $m[0], $text, flags: \PREG_UNMATCHED_AS_NULL);
    }
}
