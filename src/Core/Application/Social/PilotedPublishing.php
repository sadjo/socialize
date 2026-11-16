<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;

/**
 * Publication sur le serveur d'un compte piloté d'ici (connexion Mastodon), pour l'import d'une archive Instagram :
 * ses publications partent sur son serveur, sans copie ici.
 */
interface PilotedPublishing
{
    /**
     * @return positive-int
     */
    public function maxMedia(Account $account): int;

    /**
     * @return positive-int
     */
    public function maxCharacters(Account $account): int;

    /**
     * Publie un message et ses médias (dans l'ordre), éventuellement en réponse au précédent (suite d'un carrousel).
     * Visibilité « vous seul » : message direct sans destinataire.
     *
     * @param list<array{path: string, mimeType: string}> $media
     *
     * @return string identifiant du message sur le serveur du compte
     *
     * @throws PilotedRateLimited  débit du serveur atteint : réessayer plus tard
     * @throws PilotedActionFailed refus du serveur
     */
    public function publish(Account $account, array $media, string $text, Visibility $visibility, string $idempotencyKey, ?string $inReplyTo = null): string;
}
