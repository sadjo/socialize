<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Event\AccountDeleting;
use App\Core\Domain\Media\MediaAttachment;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Compte supprimé : les fichiers de ses publications et de ses stories (originaux et copies), sa photo de profil et son image de couverture.
 */
#[AsEventListener]
final readonly class AccountFilesCleaner
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaIngestor $ingestor,
        private FilesystemOperator $derivativesStorage,
    ) {
    }

    public function __invoke(AccountDeleting $event): void
    {
        /** @var list<MediaAttachment> $media */
        $media = $this->em->createQuery(
            'SELECT m FROM '.MediaAttachment::class.' m LEFT JOIN m.post p LEFT JOIN m.story s WHERE p.account = :account OR s.account = :account',
        )->setParameter('account', $event->account->getId(), UuidType::NAME)->getResult();
        foreach ($media as $attachment) {
            $this->ingestor->delete($attachment);
        }
        foreach (ProfileImages::filesOf($event->account) as $path) {
            try {
                $this->derivativesStorage->delete($path);
            } catch (FilesystemException) {
                // Fichier déjà absent.
            }
        }
    }
}
