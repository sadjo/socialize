<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

use App\Core\Domain\Event\AccountDeleting;
use App\Import\Instagram\Infrastructure\Doctrine\InstagramImportRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Compte supprimé : les archives Instagram encore sur le serveur (import en cours ou interrompu).
 */
#[AsEventListener]
final readonly class AccountImportsCleaner
{
    public function __construct(
        private InstagramImportRepository $imports,
        private ArchiveUploads $uploads,
    ) {
    }

    public function __invoke(AccountDeleting $event): void
    {
        foreach ($this->imports->findBy(['account' => $event->account]) as $import) {
            $this->uploads->cleanUp($import);
        }
    }
}
