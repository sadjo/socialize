<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Federation\Application\BackfillAccount;
use App\Integration\Mastodon\Domain\MastodonPublication;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonPublicationRepository;
use App\Integration\Mastodon\Infrastructure\MastodonClient;
use App\Integration\Mastodon\Infrastructure\MastodonVault;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Envoie la publication sur le serveur Mastodon : chaque média, attendu jusqu'à ce que le serveur l'ait traité (une
 * vidéo est convertie en différé), puis le statut. Les fichiers sont supprimés ensuite, qu'il soit parti ou non.
 */
#[AsMessageHandler]
final readonly class PublishToMastodonHandler
{
    /** Attente du traitement des médias par le serveur : 90 essais, toutes les 2 secondes (3 minutes). */
    private const int READY_ATTEMPTS = 90;
    private const int READY_INTERVAL = 2;

    public function __construct(
        private MastodonPublicationRepository $publications,
        private MastodonClient $client,
        private MastodonVault $vault,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
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
            $token = $this->vault->decrypt($token);
            $ids = [];
            foreach ($message->media as $media) {
                $uploaded = $this->client->uploadMedia($domain, $token, $media['path'], $media['mimeType'], $media['alt']);
                $ids[] = $uploaded['id'];
                if (!$uploaded['ready']) {
                    $this->waitFor($domain, $token, $uploaded['id']);
                }
            }
            $status = $this->client->postStatus($domain, $token, $message->text, null, $message->visibility, $publication->getId()->toRfc4122(), $ids, $message->spoiler);
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

    private function waitFor(string $domain, string $token, string $mediaId): void
    {
        for ($attempt = 0; $attempt < self::READY_ATTEMPTS; ++$attempt) {
            $this->clock->sleep(self::READY_INTERVAL);
            if ($this->client->mediaReady($domain, $token, $mediaId)) {
                return;
            }
        }
        throw new MastodonException(\sprintf('%s n’a pas fini de traiter un média : publiez de nouveau un peu plus tard.', $domain));
    }
}
