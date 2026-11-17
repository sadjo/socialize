<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Account\Account;
use App\Media\Infrastructure\VipsImageProcessor;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Photo de profil et image de couverture d'un compte. Pour un compte distant (compte Mastodon connecté), le fichier
 * déposé ici surcharge l'image venue de son instance ; le retirer fait revenir celle-ci.
 */
final readonly class ProfileImages
{
    public function __construct(
        private FilesystemOperator $derivativesStorage,
        private VipsImageProcessor $images,
    ) {
    }

    public function replaceAvatar(Account $account, File $file): void
    {
        $path = $this->store('avatars', $account, $this->images->avatar($this->read($file)));
        $this->delete($account->getAvatarPath());
        $account->changeAvatar($path);
    }

    public function removeAvatar(Account $account): void
    {
        $this->delete($account->getAvatarPath());
        $account->changeAvatar(null);
    }

    public function replaceCover(Account $account, File $file): void
    {
        $path = $this->store('covers', $account, $this->images->cover($this->read($file)));
        $this->delete($account->getCoverPath());
        $account->changeCover($path);
    }

    public function removeCover(Account $account): void
    {
        $this->delete($account->getCoverPath());
        $account->changeCover(null);
    }

    private function read(File $file): string
    {
        return (string) file_get_contents($file->getPathname());
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
