<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Crosspost\Meta\Application\InstagramApp;
use App\Crosspost\Meta\Application\InstagramConnections;
use App\Crosspost\Meta\Application\InstagramException;
use App\Crosspost\Meta\Application\InstagramLookup;
use App\Crosspost\Meta\Domain\InstagramConnection;
use App\Crosspost\Meta\Infrastructure\InstagramClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnostic de la vérification des comptes Instagram mentionnés (« ig:@nom ») pour un compte : mode de connexion,
 * droits accordés au jeton, pause éventuelle, puis réponse brute de Meta. Le jeton n'est jamais affiché.
 */
#[AsCommand(name: 'app:instagram:mentions', description: 'Diagnostic de la vérification des comptes Instagram mentionnés')]
final readonly class InstagramMentionsCheckCommand
{
    private const array NEEDED = ['instagram_basic', 'pages_read_engagement'];

    public function __construct(
        private UserRepository $users,
        private InstagramConnections $connections,
        private InstagramLookup $lookup,
        private InstagramClient $client,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Compte Socialize (adresse e-mail ou nom d’utilisateur)')] string $identifier,
        #[Argument(description: 'Nom d’un compte Instagram professionnel à chercher')] string $instagram = 'instagram',
        #[Option(description: 'Lever la pause de la vérification pour ce compte')] bool $resume = false,
    ): int {
        $user = $this->users->loadUserByIdentifier($identifier);
        if (!$user instanceof User) {
            $io->error(\sprintf('Aucun compte ne correspond à « %s ».', $identifier));

            return Command::FAILURE;
        }
        $account = $user->getAccount();
        $connection = $this->connections->usable($account);
        if (!$connection instanceof InstagramConnection) {
            $io->error('Instagram n’est pas connecté (ou la connexion a expiré) pour ce compte.');

            return Command::FAILURE;
        }
        $io->definitionList(
            ['Compte Instagram' => '@'.$connection->getUsername().' ('.$connection->getInstagramUserId().')'],
            ['Connexion' => $connection->isUnifiedLogin() ? 'unifiée (Facebook) : vérification possible' : 'Instagram directe : vérification impossible (Business Discovery n’existe qu’avec la connexion unifiée)'],
            ['Connecté le' => $connection->getConnectedAt()->format('d/m/Y H:i')],
            ['Pause' => $this->lookup->isPaused($account) ? 'oui (refus de Meta récent)' : 'non'],
        );
        if (!$connection->isUnifiedLogin()) {
            return Command::FAILURE;
        }
        if ($resume) {
            $this->lookup->resume($account);
            $io->success('Pause levée.');
        }

        $token = $this->connections->token($connection);
        $app = $this->connections->appFor($account);
        if ($app instanceof InstagramApp) {
            try {
                $scopes = $this->client->grantedScopes($app->id, $app->secret, $token);
                $io->writeln('Droits accordés au jeton : '.([] !== $scopes ? implode(', ', $scopes) : 'inconnus'));
                $missing = array_diff(self::NEEDED, $scopes);
                if ([] !== $scopes && [] !== $missing) {
                    $io->warning(\sprintf('Droit manquant : %s. Ajoutez-le à la configuration Facebook Login for Business, puis reconnectez Instagram dans les réglages.', implode(', ', $missing)));
                }
            } catch (InstagramException $e) {
                $io->writeln('Droits accordés au jeton : illisibles ('.$e->getMessage().')');
            }
        }

        foreach (['username,name,profile_picture_url,followers_count', 'username,followers_count'] as $fields) {
            try {
                $found = $this->client->businessDiscoveryRaw($connection->getInstagramUserId(), $token, $instagram, $fields);
                $io->success(\sprintf('Meta répond pour @%s (champs %s) : %s', $instagram, $fields, json_encode($found, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)));

                return Command::SUCCESS;
            } catch (InstagramException $e) {
                $io->error(\sprintf('Meta refuse (champs %s) : %s', $fields, $e->getMessage()));
            }
        }

        return Command::FAILURE;
    }
}
