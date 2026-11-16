<?php

declare(strict_types=1);

namespace App\Core\Application\Notification;

use App\Core\Application\Post\PostAccess;
use App\Core\Application\Story\StoryAccess;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Event\StoryPublished;
use App\Core\Domain\Notification\NotificationPreferences;
use App\Core\Domain\Notification\PushKind;
use App\Core\Domain\Notification\PushSubscription;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\NotificationPreferencesRepository;
use App\Core\Infrastructure\Doctrine\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Notifications sur les appareils (Web Push, application ajoutée à l'écran d'accueil) : nouvelles publications et
 * stories des comptes suivis (pour les abonnés de cette instance qui ont le droit de les voir), J'aime et
 * commentaires reçus. Chacun choisit ce qu'il reçoit ; l'envoi se fait en tâche de fond.
 */
final readonly class PushNotifications
{
    private const int EXCERPT = 120;

    public function __construct(
        private PushSubscriptionRepository $subscriptions,
        private NotificationPreferencesRepository $preferences,
        private FollowRepository $follows,
        private PostAccess $posts,
        private StoryAccess $stories,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Abonne un appareil (ou le rattache au compte qui s'y connecte).
     */
    public function subscribe(Account $account, string $endpoint, string $publicKey, string $authToken): void
    {
        $subscription = $this->subscriptions->forEndpoint($endpoint);
        if ($subscription instanceof PushSubscription) {
            $subscription->renew($account, $publicKey, $authToken);
        } else {
            $this->em->persist(new PushSubscription($account, $endpoint, $publicKey, $authToken, $this->clock->now()));
        }
        $this->em->flush();
    }

    public function unsubscribe(Account $account, string $endpoint): void
    {
        $subscription = $this->subscriptions->forEndpoint($endpoint);
        if ($subscription instanceof PushSubscription && $subscription->getAccount()->getId()->equals($account->getId())) {
            $this->em->remove($subscription);
            $this->em->flush();
        }
    }

    public function devicesCount(Account $account): int
    {
        return \count($this->subscriptions->of($account));
    }

    public function preferences(Account $account): NotificationPreferences
    {
        return $this->preferences->forAccount($account) ?? new NotificationPreferences($account);
    }

    public function choose(Account $account, bool $posts, bool $stories, bool $likes, bool $comments): void
    {
        $preferences = $this->preferences->forAccount($account);
        if (!$preferences instanceof NotificationPreferences) {
            $preferences = new NotificationPreferences($account);
            $this->em->persist($preferences);
        }
        $preferences->choose($posts, $stories, $likes, $comments);
        $this->em->flush();
    }

    public function liked(Account $recipient, Account $actor, Post $post): void
    {
        $this->push($recipient, PushKind::Like, $actor->getDisplayName().' a aimé votre publication', $this->excerpt($post->getCaption()), '/p/'.$post->getPublicId(), 'like-'.$post->getPublicId());
    }

    public function commented(Account $recipient, Comment $comment): void
    {
        $post = $comment->getPost();
        $this->push($recipient, PushKind::Comment, $comment->getAccount()->getDisplayName().' a commenté votre publication', $this->excerpt($comment->getContent()), '/p/'.$post->getPublicId().'#comment-'.$comment->getPublicId(), 'comment-'.$comment->getPublicId());
    }

    /**
     * Nouvelle publication : ses abonnés d'ici qui peuvent la voir (pas les publications importées d'une archive).
     */
    #[AsEventListener]
    public function onPostPublished(PostPublished $event): void
    {
        $post = $event->post;
        $author = $post->getAccount();
        if (!$author->isLocal() || $post->isImported()) {
            return;
        }
        $body = $this->excerpt($post->getCaption());
        foreach ($this->follows->followersOf($author) as $follower) {
            if ($follower->isLocal() && $this->posts->canView($post, $follower)) {
                $this->push($follower, PushKind::Post, 'Nouvelle publication de '.$author->getDisplayName(), '' !== $body ? $body : ($post->hasVideo() ? 'Nouvelle vidéo' : 'Nouvelle photo'), '/p/'.$post->getPublicId(), 'post-'.$post->getPublicId());
            }
        }
    }

    #[AsEventListener]
    public function onStoryPublished(StoryPublished $event): void
    {
        $story = $event->story;
        $author = $story->getAccount();
        if (!$author->isLocal()) {
            return;
        }
        $now = $this->clock->now();
        foreach ($this->follows->followersOf($author) as $follower) {
            if ($follower->isLocal() && $this->stories->canView($story, $follower, $now)) {
                $this->push($follower, PushKind::Story, $author->getDisplayName().' a publié une story', $this->excerpt($story->getText() ?? ''), '/@'.$author->getUsername().'/stories', 'story-'.$author->getUsername());
            }
        }
    }

    private function push(Account $recipient, PushKind $kind, string $title, string $body, string $url, string $tag): void
    {
        if (!$recipient->isLocal() || !$this->preferences($recipient)->wants($kind) || [] === $this->subscriptions->of($recipient)) {
            return;
        }
        $this->bus->dispatch(new SendPush($recipient->getId()->toRfc4122(), ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag]));
    }

    private function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::EXCERPT ? mb_substr($text, 0, self::EXCERPT - 1).'…' : $text;
    }
}
