<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use App\Crosspost\Meta\Domain\InstagramCrosspost;
use App\Crosspost\Meta\Infrastructure\Doctrine\InstagramCrosspostRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Fait avancer un envoi vers Instagram d'une étape, puis se renvoie avec un délai tant qu'il n'est pas terminé.
 * Une erreur temporaire (réseau, limite de débit) est réessayée par Messenger.
 */
#[AsMessageHandler]
final readonly class PublishToInstagramHandler
{
    public function __construct(
        private InstagramCrosspostRepository $crossposts,
        private InstagramPublishing $publishing,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(PublishToInstagram $message): void
    {
        $crosspost = $this->crossposts->find(Uuid::fromString($message->crosspostId));
        if (!$crosspost instanceof InstagramCrosspost) {
            return;
        }
        try {
            $delay = $this->publishing->advance($crosspost);
        } catch (InstagramException $e) {
            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
        if (null !== $delay) {
            $this->bus->dispatch(new PublishToInstagram($message->crosspostId), [new DelayStamp($delay * 1000)]);
        }
    }
}
