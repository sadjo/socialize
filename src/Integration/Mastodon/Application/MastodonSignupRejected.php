<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Inscription refusée par le serveur Mastodon, avec ses messages champ par champ (username, email, password…).
 */
final class MastodonSignupRejected extends MastodonException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors,
    ) {
        parent::__construct($message);
    }
}
