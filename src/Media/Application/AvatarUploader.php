<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Account\Account;
use App\Media\Infrastructure\VipsImageProcessor;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;

final readonly class AvatarUploader
{
    public function __construct(
        private FilesystemOperator $derivativesStorage,
        private VipsImageProcessor $images,
    ) {
    }

    public function replace(Account $account, File $file): void
    {
        $contents = $this->images->avatar((string) file_get_contents($file->getPathname()));
        $path = \sprintf('avatars/%s-%s.webp', $account->getId()->toBase58(), bin2hex(random_bytes(4)));
        $this->derivativesStorage->write($path, $contents);

        $previous = $account->getAvatarPath();
        $account->changeAvatar($path);
        if (null !== $previous) {
            $this->derivativesStorage->delete($previous);
        }
    }
}
