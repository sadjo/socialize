<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Core\Application\Social\PilotedActionFailed;
use App\Core\Application\Social\PilotedPublishing;
use App\Core\Application\Social\PilotedRateLimited;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Integration\Mastodon\Domain\MastodonUser;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use App\Integration\Mastodon\Infrastructure\MastodonVault;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Publication sur le compte Mastodon d'une personne connectée avec, pour l'import de son archive Instagram.
 */
#[AsAlias(PilotedPublishing::class)]
final readonly class MastodonPublishing implements PilotedPublishing
{
    public function __construct(
        private MastodonStatusSender $sender,
        private MastodonPublisher $publisher,
        private MastodonUserRepository $users,
        private MastodonVault $vault,
    ) {
    }

    public function maxMedia(Account $account): int
    {
        return $this->publisher->limits($this->user($account))->maxMedia;
    }

    public function maxCharacters(Account $account): int
    {
        return $this->publisher->limits($this->user($account))->maxCharacters;
    }

    public function publish(Account $account, array $media, string $text, Visibility $visibility, string $idempotencyKey, ?string $inReplyTo = null): string
    {
        $user = $this->user($account);
        $token = $user->getTokenEncrypted() ?? throw new PilotedActionFailed('Vous vous êtes déconnecté : reconnectez-vous avec Mastodon pour reprendre l’envoi.');
        try {
            return $this->sender->send((string) $account->getDomain(), $this->vault->decrypt($token), $media, $text, match ($visibility) {
                Visibility::Public => 'public',
                Visibility::Unlisted => 'unlisted',
                Visibility::Followers, Visibility::Groups => 'private',
                Visibility::Private => 'direct',
            }, $idempotencyKey, $inReplyTo)['id'];
        } catch (MastodonRateLimited $e) {
            throw new PilotedRateLimited($e->getMessage(), $e->retryAfter, $e);
        } catch (MastodonException $e) {
            throw new PilotedActionFailed($e->getMessage(), $e->getCode(), $e);
        } catch (\RuntimeException $e) {
            throw new PilotedActionFailed('Jeton Mastodon illisible : reconnectez-vous avec Mastodon.', $e->getCode(), $e);
        }
    }

    private function user(Account $account): MastodonUser
    {
        return $this->users->forAccount($account) ?? throw new PilotedActionFailed('Ce compte n’est pas connecté avec Mastodon.');
    }
}
