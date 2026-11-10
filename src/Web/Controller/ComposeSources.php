<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Integration\Immich\Application\ImmichConnections;
use App\Integration\Immich\Application\ImmichException;
use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\ImmichClient;
use App\Web\Security\CurrentAccount;

/**
 * Sources des médias des écrans de création (appareil, Immich) et lecture de l'ordre envoyé.
 */
final readonly class ComposeSources
{
    public function __construct(
        private ImmichConnections $connections,
        private ImmichClient $client,
        private CurrentAccount $currentAccount,
    ) {
    }

    /**
     * Connexion Immich du compte et ses albums (liste vide si Immich ne répond pas : l'appareil reste disponible).
     *
     * @return array{immich: ?ImmichConnection, immich_albums: list<array{id: string, name: string, count: int}>}
     */
    public function forCurrentAccount(): array
    {
        $connection = $this->connections->of($this->currentAccount->get());
        $albums = [];
        if ($connection instanceof ImmichConnection) {
            try {
                $albums = $this->client->albums(...$this->connections->credentials($connection));
            } catch (ImmichException|\RuntimeException) {
                $albums = [];
            }
        }

        return ['immich' => $connection, 'immich_albums' => $albums];
    }

    /**
     * @return list<string> jetons « n-<i> » et « i-<id> », dans l'ordre envoyé
     */
    public static function order(?string $order): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', (string) $order)), static fn (string $t): bool => '' !== $t));
    }

    /**
     * Textes alternatifs par jeton ; sans ordre (JavaScript désactivé), par position du fichier.
     *
     * @param array<int|string, ?string> $altTexts
     *
     * @return array<string, ?string>
     */
    public static function altTexts(array $altTexts): array
    {
        $byToken = [];
        foreach ($altTexts as $key => $text) {
            $byToken[\is_int($key) ? 'n-'.$key : $key] = $text;
        }

        return $byToken;
    }
}
