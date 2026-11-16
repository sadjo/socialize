<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Federation\Application\BackfillAccount;
use App\Integration\Mastodon\Domain\MastodonPublication;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonPublicationRepository;
use App\Integration\Mastodon\Infrastructure\MastodonVault;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Envoie la publication sur le serveur Mastodon (MastodonStatusSender). Les fichiers sont supprimés ensuite, qu'elle
 * soit partie ou non.
 */
#[AsMessageHandler]
final readonly class PublishToMastodonHandler
{
    public function __construct(
        private MastodonPublicationRepository $publications,
        private MastodonStatusSender $sender,
        private MastodonVault $vault,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function __invoke(PublishToMastodon $message): void
    {
        $publication = $this->publications->find($message->publicationId);
        try {
            if ($publication instanceof MastodonPublication && $publication->isPending()) {
                $this->send($publication, $message);
            }
        } finally {
            foreach ($message->media as $media) {
                $this->filesystem->remove(\dirname($media['path']));
            }
        }
    }

    private function send(MastodonPublication $publication, PublishToMastodon $message): void
    {
        $account = $publication->getUser()->getAccount();
        $domain = (string) $account->getDomain();
        $token = $publication->getUser()->getTokenEncrypted();
        try {
            if (null === $token) {
                throw new MastodonException('Vous vous êtes déconnecté avant l’envoi : reconnectez-vous avec Mastodon et publiez de nouveau.');
            }
            $status = $this->sender->send($domain, $this->vault->decrypt($token), $message->media, $message->text, $message->visibility, $publication->getId()->toRfc4122(), spoiler: $message->spoiler);
            $publication->sent($status['uri']);
            $this->em->flush();
        } catch (MastodonException $e) {
            $publication->failed($e->getMessage());
            $this->em->flush();

            return;
        } catch (\RuntimeException $e) {
            $this->logger->error('Publication Mastodon non envoyée', ['exception' => $e]);
            $publication->failed('Jeton Mastodon illisible : reconnectez-vous avec Mastodon et publiez de nouveau.');
            $this->em->flush();

            return;
        }

        // Son profil ici : la publication arrive avec son historique.
        $this->bus->dispatch(new BackfillAccount($account->getId()->toRfc4122()));
    }
}
