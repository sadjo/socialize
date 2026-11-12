<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Integration\Immich\Application\ImmichConnections;
use App\Integration\Immich\Application\ImmichException;
use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\ImmichClient;
use App\Integration\SynologyPhotos\Application\SynologyPhotosConnections;
use App\Integration\SynologyPhotos\Application\SynologyPhotosException;
use App\Integration\SynologyPhotos\Domain\SynologyPhotosConnection;
use App\Integration\SynologyPhotos\Infrastructure\SynologyPhotosClient;
use App\Web\Security\CurrentAccount;

/**
 * Sources des médias des écrans de création (appareil, Immich, Synology Photos) et lecture de l'ordre envoyé.
 */
final readonly class ComposeSources
{
    public function __construct(
        private ImmichConnections $connections,
        private ImmichClient $client,
        private SynologyPhotosConnections $synologyConnections,
        private SynologyPhotosClient $synologyClient,
        private CurrentAccount $currentAccount,
    ) {
    }

    /**
     * Photothèques du compte et leurs albums (liste vide si l'une ne répond pas : l'appareil reste disponible).
     *
     * @return array{immich: ?ImmichConnection, immich_albums: list<array{id: string, name: string, count: int}>, synology: ?SynologyPhotosConnection, synology_albums: list<array{id: int, name: string, count: int}>}
     */
    public function forCurrentAccount(): array
    {
        $account = $this->currentAccount->get();
        $connection = $this->connections->of($account);
        $albums = [];
        if ($connection instanceof ImmichConnection) {
            try {
                $albums = $this->client->albums(...$this->connections->credentials($connection));
            } catch (ImmichException|\RuntimeException) {
                $albums = [];
            }
        }

        $synology = $this->synologyConnections->of($account);
        $synologyAlbums = [];
        if ($synology instanceof SynologyPhotosConnection) {
            try {
                $synologyAlbums = $this->synologyClient->albums(...$this->synologyConnections->session($synology));
            } catch (SynologyPhotosException $e) {
                // Session expirée : l'onglet reste, la photothèque y proposera de se reconnecter.
                $this->synologyConnections->noteFailure($synology, $e);
            } catch (\RuntimeException) {
                $synologyAlbums = [];
            }
        }

        return ['immich' => $connection, 'immich_albums' => $albums, 'synology' => $synology, 'synology_albums' => $synologyAlbums];
    }

    /**
     * @return list<string> jetons « n-<i> », « i-<id> » et « s-<espace>-<id> », dans l'ordre envoyé
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
