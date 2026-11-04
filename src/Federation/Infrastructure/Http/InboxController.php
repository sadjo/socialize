<?php

declare(strict_types=1);

namespace App\Federation\Infrastructure\Http;

use App\Core\Domain\Account\Account;
use App\Federation\Application\Json;
use App\Federation\Application\ProcessInboxActivity;
use App\Federation\Application\RemoteAccounts;
use App\Federation\Infrastructure\FederationException;
use App\Federation\Infrastructure\HttpSignature;
use App\Federation\Infrastructure\SignatureException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Boîtes de réception : vérifie la signature HTTP et l'auteur de l'activité, puis la confie à Messenger.
 */
#[AsController]
final readonly class InboxController
{
    private const int MAX_BYTES = 1_000_000;

    public function __construct(
        private RemoteAccounts $remote,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/inbox', name: 'ap_shared_inbox', methods: ['POST'])]
    #[Route('/actor/inbox', name: 'ap_instance_inbox', methods: ['POST'])]
    #[Route('/users/{username}/inbox', name: 'ap_inbox', requirements: ['username' => '[a-z0-9_.]+'], methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();
        if (\strlen($body) > self::MAX_BYTES) {
            return $this->error('Activité trop volumineuse.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $activity = json_decode($body, true);
        if (!\is_array($activity) || null === ($type = Json::string($activity, 'type'))) {
            return $this->error('JSON ActivityPub invalide.', Response::HTTP_BAD_REQUEST);
        }

        $signature = HttpSignature::parse($request->headers->get('Signature'));
        if (null === $signature) {
            return $this->error('Signature HTTP absente.', Response::HTTP_UNAUTHORIZED);
        }
        $actorUri = explode('#', $signature['keyId'])[0];

        try {
            $signer = $this->verify($request, $body, $actorUri);
        } catch (FederationException $e) {
            // Un compte supprimé n'est plus joignable : sa suppression n'a rien à vérifier.
            if ('Delete' === $type) {
                return new Response(status: Response::HTTP_ACCEPTED);
            }

            return $this->error('Signataire introuvable : '.$e->getMessage(), Response::HTTP_UNAUTHORIZED);
        } catch (SignatureException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        if (Json::id($activity, 'actor') !== $signer->getApUri()) {
            return $this->error('L’activité n’a pas été signée par son auteur.', Response::HTTP_UNAUTHORIZED);
        }

        $this->bus->dispatch(new ProcessInboxActivity($body, $signer->getId()->toRfc4122()));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    private function verify(Request $request, string $body, string $actorUri): Account
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[mb_strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        $account = $this->remote->byUri($actorUri);
        try {
            HttpSignature::verify('POST', $request->getRequestUri(), $headers, $body, (string) $account->getPublicKeyPem());
        } catch (SignatureException) {
            // La clé a peut-être changé : on relit l'acteur une fois.
            $account = $this->remote->byUri($actorUri, true);
            HttpSignature::verify('POST', $request->getRequestUri(), $headers, $body, (string) $account->getPublicKeyPem());
        }

        return $account;
    }

    private function error(string $message, int $status): JsonResponse
    {
        $this->logger->info('Activité refusée', ['reason' => $message, 'status' => $status]);

        return new JsonResponse(['error' => $message], $status);
    }
}
