<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Adresses ActivityPub de l'instance, construites à partir de DEFAULT_URI
 * (elles servent d'identifiants dans tout le fédivers et ne doivent pas changer).
 */
final readonly class ApUrls
{
    public const string PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

    private string $base;

    public function __construct(
        #[Autowire(env: 'DEFAULT_URI')]
        string $defaultUri,
        #[Autowire(param: 'app.local_domain')]
        private string $domain,
    ) {
        $parts = parse_url($defaultUri);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? $domain;
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $this->base = $scheme.'://'.$host.$port;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function base(): string
    {
        return $this->base;
    }

    public function actor(Account $account): string
    {
        return $account->getApUri() ?? $this->base.'/users/'.$account->getUsername();
    }

    public function key(Account $account): string
    {
        return $this->actor($account).'#main-key';
    }

    public function inbox(Account $account): string
    {
        return $this->actor($account).'/inbox';
    }

    public function outbox(Account $account): string
    {
        return $this->actor($account).'/outbox';
    }

    public function followers(Account $account): string
    {
        return $this->actor($account).'/followers';
    }

    public function following(Account $account): string
    {
        return $this->actor($account).'/following';
    }

    public function sharedInbox(): string
    {
        return $this->base.'/inbox';
    }

    public function profile(Account $account): string
    {
        return $account->getProfileUrl() ?? $this->base.'/@'.$account->getUsername();
    }

    public function note(Post $post): string
    {
        return $post->getApUri() ?? $this->base.'/p/'.$post->getPublicId();
    }

    public function comment(Comment $comment): string
    {
        return $comment->getApUri() ?? $this->base.'/c/'.$comment->getPublicId();
    }

    public function activity(Account $account, string $kind, ?Uuid $id = null): string
    {
        return $this->actor($account).'#'.$kind.'/'.($id ?? Uuid::v7())->toBase58();
    }

    public function instanceActor(): string
    {
        return $this->base.'/actor';
    }

    public function hashtag(string $tag): string
    {
        return $this->base.'/tags/'.rawurlencode(mb_strtolower($tag));
    }

    public function isLocal(string $uri): bool
    {
        return str_starts_with($uri, $this->base.'/');
    }
}
