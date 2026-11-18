<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Core\Domain\Account\Account;
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
        $this->deleteFilesOf($event->account);
    }

    /**
     * Aussi appelée pour un compte distant supprimé sur son instance : les copies de ses médias fabriquées ici, sans
     * annoncer de suppression au fédivers (AccountDeleting).
     */
    public function deleteFilesOf(Account $account): void
    {
        /** @var list<MediaAttachment> $media */
        $media = $this->em->createQuery(
            'SELECT m FROM '.MediaAttachment::class.' m LEFT JOIN m.post p LEFT JOIN m.story s WHERE p.account = :account OR s.account = :account',
        )->setParameter('account', $account->getId(), UuidType::NAME)->getResult();
        foreach ($media as $attachment) {
            $this->ingestor->delete($attachment);
        }
        foreach (ProfileImages::filesOf($account) as $path) {
            try {
                $this->derivativesStorage->delete($path);
            } catch (FilesystemException) {
                // Fichier déjà absent.
            }
        }
    }
}
