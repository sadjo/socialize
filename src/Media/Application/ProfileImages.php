<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Account\Account;
use App\Media\Infrastructure\VipsImageProcessor;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Photo de profil et image de couverture d'un compte. Chaque image garde sa source et le cadre choisi, pour être
 * recadrée plus tard sans renvoyer le fichier. Pour un compte distant (compte Mastodon connecté), le fichier déposé
 * ici surcharge l'image venue de son instance ; le retirer fait revenir celle-ci.
 */
final readonly class ProfileImages
{
    public function __construct(
        private FilesystemOperator $derivativesStorage,
        private VipsImageProcessor $images,
    ) {
    }

    /**
     * Applique un envoi de formulaire : nouveau fichier (avec son cadre), retrait, ou nouveau cadre de l'image actuelle.
     */
    public function apply(Account $account, ProfileImage $kind, ?File $file, ?string $crop, bool $remove = false): void
    {
        $crop = ImageCrop::fromString($crop);
        if ($file instanceof File) {
            $this->replace($account, $kind, $file, $crop);
        } elseif ($remove) {
            $this->remove($account, $kind);
        } elseif ($crop instanceof ImageCrop && $crop->toString() !== $this->crop($account, $kind)) {
            $this->recrop($account, $kind, $crop);
        }
    }

    public function replace(Account $account, ProfileImage $kind, File $file, ?ImageCrop $crop = null): void
    {
        $source = $this->images->profileSource((string) file_get_contents($file->getPathname()));
        $framed = $this->images->framed($source, $kind->width(), $kind->height(), $crop);
        $sourcePath = $this->store($kind->directory().'/sources', $account, $source);
        $path = $this->store($kind->directory(), $account, $framed);
        $this->deleteFiles($account, $kind);
        $this->change($account, $kind, $path, $sourcePath, $crop?->toString());
    }

    /**
     * Nouveau cadre dans la source gardée ; sans source (image déposée avant le recadrage), rien ne change.
     */
    public function recrop(Account $account, ProfileImage $kind, ImageCrop $crop): void
    {
        $sourcePath = ProfileImage::Avatar === $kind ? $account->getAvatarSourcePath() : $account->getCoverSourcePath();
        if (null === $sourcePath) {
            return;
        }
        $framed = $this->images->framed($this->derivativesStorage->read($sourcePath), $kind->width(), $kind->height(), $crop);
        $path = $this->store($kind->directory(), $account, $framed);
        $this->delete(ProfileImage::Avatar === $kind ? $account->getAvatarPath() : $account->getCoverPath());
        $this->change($account, $kind, $path, $sourcePath, $crop->toString());
    }

    public function remove(Account $account, ProfileImage $kind): void
    {
        $this->deleteFiles($account, $kind);
        $this->change($account, $kind, null, null, null);
    }

    /**
     * Tous les fichiers des images d'un compte (à sa suppression).
     *
     * @return list<string>
     */
    public static function filesOf(Account $account): array
    {
        return array_values(array_filter([
            $account->getAvatarPath(), $account->getAvatarSourcePath(),
            $account->getCoverPath(), $account->getCoverSourcePath(),
        ]));
    }

    private function crop(Account $account, ProfileImage $kind): ?string
    {
        return ProfileImage::Avatar === $kind ? $account->getAvatarCrop() : $account->getCoverCrop();
    }

    private function change(Account $account, ProfileImage $kind, ?string $path, ?string $sourcePath, ?string $crop): void
    {
        ProfileImage::Avatar === $kind
            ? $account->changeAvatar($path, $sourcePath, $crop)
            : $account->changeCover($path, $sourcePath, $crop);
    }

    private function deleteFiles(Account $account, ProfileImage $kind): void
    {
        if (ProfileImage::Avatar === $kind) {
            $this->delete($account->getAvatarPath());
            $this->delete($account->getAvatarSourcePath());
        } else {
            $this->delete($account->getCoverPath());
            $this->delete($account->getCoverSourcePath());
        }
    }

    private function store(string $directory, Account $account, string $contents): string
    {
        $path = \sprintf('%s/%s-%s.webp', $directory, $account->getId()->toBase58(), bin2hex(random_bytes(4)));
        $this->derivativesStorage->write($path, $contents);

        return $path;
    }

    private function delete(?string $path): void
    {
        if (null === $path) {
            return;
        }
        try {
            $this->derivativesStorage->delete($path);
        } catch (FilesystemException) {
            // Fichier déjà absent.
        }
    }
}
