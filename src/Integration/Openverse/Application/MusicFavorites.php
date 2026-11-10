<?php

declare(strict_types=1);

namespace App\Integration\Openverse\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Music\MusicFavorite;
use App\Core\Infrastructure\Doctrine\MusicFavoriteRepository;
use App\Integration\Openverse\Infrastructure\OpenverseClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Musiques favorites d'un compte, proposées à l'ouverture du sélecteur de musique.
 */
final readonly class MusicFavorites
{
    public function __construct(
        private MusicFavoriteRepository $favorites,
        private OpenverseClient $openverse,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Ajoute le morceau aux favoris, ou l'en retire s'il y est déjà.
     *
     * @return bool true si le morceau est maintenant en favori
     *
     * @throws OpenverseException
     */
    public function toggle(Account $account, string $openverseId): bool
    {
        $existing = $this->favorites->findOne($account, $openverseId);
        if ($existing instanceof MusicFavorite) {
            $this->em->remove($existing);
            $this->em->flush();

            return false;
        }
        if ($this->favorites->countFor($account) >= MusicFavorite::MAX_PER_ACCOUNT) {
            throw new OpenverseException(\sprintf('Vous avez déjà %d musiques en favori : retirez-en une avant d’en ajouter.', MusicFavorite::MAX_PER_ACCOUNT));
        }

        $track = $this->openverse->track($openverseId);
        if (null === $track->fileUrl) {
            throw new OpenverseException('Ce morceau ne peut pas être téléchargé.');
        }
        $this->em->persist(new MusicFavorite(
            $account,
            $track->id,
            $track->title,
            $track->artist,
            $track->license,
            $track->licenseVersion,
            $track->provider,
            $track->duration,
            $track->genres,
            $track->hasThumbnail,
        ));
        $this->em->flush();

        return true;
    }

    /**
     * Favoris du compte, du plus récent au plus ancien, sous la forme des résultats de recherche.
     *
     * @return list<OpenverseTrack>
     */
    public function of(Account $account): array
    {
        return array_map(static fn (MusicFavorite $f): OpenverseTrack => new OpenverseTrack(
            id: $f->getOpenverseId(),
            title: $f->getTitle(),
            artist: $f->getArtist(),
            license: $f->getLicense(),
            licenseVersion: $f->getLicenseVersion(),
            licenseUrl: null,
            sourceUrl: null,
            provider: $f->getProvider(),
            duration: $f->getDuration(),
            fileUrl: null,
            genres: $f->getGenres(),
            hasThumbnail: $f->hasThumbnail(),
        ), $this->favorites->forAccount($account));
    }

    /**
     * Parmi ces morceaux, ceux qui sont dans les favoris du compte.
     *
     * @param list<OpenverseTrack> $tracks
     *
     * @return array<string, true>
     */
    public function among(Account $account, array $tracks): array
    {
        return $this->favorites->idsAmong($account, array_map(static fn (OpenverseTrack $t): string => $t->id, $tracks));
    }
}
