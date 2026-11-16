<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Core\Application\Social\PilotedActionFailed;
use App\Core\Application\Social\PilotedActions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\Comment;
use App\Federation\Application\ApUrls;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use App\Integration\Mastodon\Infrastructure\MastodonClient;
use App\Integration\Mastodon\Infrastructure\MastodonVault;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Interactions d'un compte Mastodon piloté d'ici, par l'API de son serveur. Publications et comptes y sont retrouvés
 * par leur adresse ActivityPub ; son serveur envoie ensuite l'activité par la fédération.
 */
#[AsAlias(PilotedActions::class)]
final readonly class MastodonActions implements PilotedActions
{
    public function __construct(
        private MastodonClient $client,
        private MastodonUserRepository $users,
        private MastodonVault $vault,
        private ApUrls $urls,
    ) {
    }

    public function like(Account $actor, Post $post): void
    {
        $this->run($actor, fn (string $domain, string $token) => $this->client->favourite($domain, $token, $this->status($domain, $token, $post)));
    }

    public function unlike(Account $actor, Post $post): void
    {
        $this->run($actor, fn (string $domain, string $token) => $this->client->favourite($domain, $token, $this->status($domain, $token, $post), 'unfavourite'));
    }

    /**
     * Réponse à la publication, qui mentionne son auteur (comme le fait une application Mastodon) : publique pour une
     * publication publique ou non listée, en message direct à l'auteur sinon, pour ne rien montrer à d'autres.
     */
    public function comment(Account $actor, Post $post, string $content): string
    {
        $author = $post->getAccount();
        $mention = '@'.$author->getUsername().'@'.($author->isLocal() ? $this->urls->domain() : $author->getDomain());
        $visibility = match ($post->getVisibility()) {
            Visibility::Public => 'public',
            Visibility::Unlisted => 'unlisted',
            default => 'direct',
        };

        return $this->run($actor, fn (string $domain, string $token): string => $this->client->postStatus(
            $domain, $token, $mention.' '.$content, $this->status($domain, $token, $post), $visibility, bin2hex(random_bytes(16)),
        )['uri']);
    }

    public function deleteComment(Comment $comment): void
    {
        $uri = $comment->getApUri();
        if (null === $uri) {
            return;
        }
        $this->run($comment->getAccount(), function (string $domain, string $token) use ($uri): void {
            $id = $this->client->resolve($domain, $token, $uri, 'statuses');
            if (null !== $id) {
                $this->client->deleteStatus($domain, $token, $id);
            }
        });
    }

    public function deletePost(Post $post): void
    {
        $uri = $post->getApUri();
        if (null === $uri) {
            return;
        }
        $this->run($post->getAccount(), function (string $domain, string $token) use ($uri): void {
            $id = $this->client->resolve($domain, $token, $uri, 'statuses');
            if (null !== $id) {
                $this->client->deleteStatus($domain, $token, $id);
            }
        });
    }

    public function follow(Account $follower, Account $following): bool
    {
        return $this->run($follower, fn (string $domain, string $token): bool => $this->client->follow($domain, $token, $this->account($domain, $token, $following))
            // Un compte d'ici accepte tout abonnement : l'acceptation arrive aussitôt par la fédération.
            || $following->isLocal());
    }

    public function unfollow(Account $follower, Account $following): void
    {
        $this->run($follower, fn (string $domain, string $token): bool => $this->client->follow($domain, $token, $this->account($domain, $token, $following), 'unfollow'));
    }

    /**
     * @template T
     *
     * @param callable(string, string): T $call serveur et jeton du compte
     *
     * @return T
     */
    private function run(Account $actor, callable $call): mixed
    {
        $user = $this->users->forAccount($actor);
        $token = $user?->getTokenEncrypted();
        $domain = $actor->getDomain();
        if (null === $token || null === $domain) {
            throw new PilotedActionFailed('Reconnectez-vous avec Mastodon pour continuer.');
        }
        try {
            return $call($domain, $this->vault->decrypt($token));
        } catch (MastodonException $e) {
            throw new PilotedActionFailed($e->getMessage(), $e->getCode(), $e);
        } catch (\RuntimeException $e) {
            throw new PilotedActionFailed('Reconnectez-vous avec Mastodon pour continuer.', $e->getCode(), $e);
        }
    }

    private function status(string $domain, string $token, Post $post): string
    {
        return $this->client->resolve($domain, $token, $this->urls->note($post), 'statuses')
            ?? throw new MastodonException(\sprintf('%s ne trouve pas cette publication.', $domain));
    }

    private function account(string $domain, string $token, Account $account): string
    {
        return $this->client->resolve($domain, $token, $this->urls->actor($account), 'accounts')
            ?? throw new MastodonException(\sprintf('%s ne trouve pas ce compte.', $domain));
    }
}
