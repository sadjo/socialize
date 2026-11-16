<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Compte Mastodon tout juste créé, dont l'adresse e-mail n'est pas encore confirmée : son jeton est refusé.
 */
final class MastodonUnconfirmed extends MastodonException
{
}
