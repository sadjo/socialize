<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Échec d'un échange avec un serveur Mastodon, avec un message à montrer tel quel.
 */
final class MastodonException extends \RuntimeException
{
}
