<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

/**
 * Tâche planifiée : met à jour l'historique des comptes Mastodon pilotés d'ici. Leur serveur n'envoie leurs
 * nouvelles publications qu'aux serveurs de leurs abonnés : sans abonné ici, leur profil serait figé.
 */
final readonly class RefreshMastodonAccounts
{
}
