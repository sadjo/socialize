<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Élément introuvable sur le serveur Mastodon (réponse 404).
 */
final class MastodonNotFound extends MastodonException
{
}
