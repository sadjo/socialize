<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Notification\PushSubscription;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\PushSubscriptionRepository;
use App\Media\Application\PostComposer;
use App\Media\Application\StoryComposer;
use App\Tests\AppWebTestCase;
use App\Tests\Support\FakePushSender;

/**
 * Notifications sur les appareils (Web Push) : abonnement d'un appareil, nouvelles publications et stories des
 * comptes suivis, J'aime et commentaires reçus ; choix de ce qu'on reçoit.
 */
final class PushNotificationTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakePushSender::$sent = [];
    }

    public function testFollowersAreNotifiedOfNewPostsAndStoriesTheyMaySee(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        self::getContainer()->get(SocialActions::class)->follow($hugo->getAccount(), $camille->getAccount());

        // Hugo active les notifications sur son téléphone.
        $this->login($hugo);
        $crawler = $this->client->request('GET', '/notifications');
        $card = $crawler->filter('[data-controller="push"]');
        self::assertSame('BCleDeTestVapid', $card->attr('data-push-key-value'));
        $this->device((string) $card->attr('data-push-token-value'), 'https://push.example/telephone-hugo');
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::getContainer()->get(PushSubscriptionRepository::class)->findAll());

        // Nouvelle publication de Camille : Hugo est notifié ; une publication privée, non.
        $composer = self::getContainer()->get(PostComposer::class);
        $post = $composer->publish($this->account($camille), 'Coucher de soleil sur la mer', [['file' => $this->jpeg(), 'alt' => null]]);
        $composer->publish($this->account($camille), 'Pour moi', [['file' => $this->jpeg(), 'alt' => null]], Visibility::Private);
        self::assertSame([[
            'endpoint' => 'https://push.example/telephone-hugo',
            'payload' => ['title' => 'Nouvelle publication de Camille', 'body' => 'Coucher de soleil sur la mer', 'url' => '/p/'.$post->getPublicId(), 'tag' => 'post-'.$post->getPublicId()],
        ]], FakePushSender::$sent);

        // Story de Camille.
        self::getContainer()->get(StoryComposer::class)->publish($this->account($camille), [$this->jpeg(1080, 1920)], 'Matin', Visibility::Public);
        $story = $this->lastSent();
        self::assertSame('Camille a publié une story', $story['title']);
        self::assertSame('/@camille/stories', $story['url']);

        // Hugo ne veut plus les stories.
        $crawler = $this->client->request('GET', '/notifications');
        $form = $crawler->selectButton('Enregistrer')->form();
        $stories = $form['notification_preferences[stories]'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $stories);
        $stories->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/notifications');
        self::getContainer()->get(StoryComposer::class)->publish($this->account($camille), [$this->jpeg(1080, 1920)], 'Soir', Visibility::Public);
        self::assertCount(2, $this->sent(), 'Plus de notification de story.');
    }

    public function testTheAuthorIsNotifiedOfLikesAndComments(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Lac', [['file' => $this->jpeg(), 'alt' => null]]);

        $this->login($camille);
        $crawler = $this->client->request('GET', '/notifications');
        $token = (string) $crawler->filter('[data-controller="push"]')->attr('data-push-token-value');
        $this->device($token, 'https://push.example/telephone-camille');
        $this->device($token, 'https://push.example/perime');

        $social = self::getContainer()->get(SocialActions::class);
        $social->toggleLike($this->account($hugo), $this->post($post));
        $social->comment($this->account($hugo), $this->post($post), 'Superbe lumière !');
        $titles = array_map(static fn (array $s): string => $s['payload']['title'], FakePushSender::$sent);
        self::assertSame(['Hugo a aimé votre publication', 'Hugo a commenté votre publication'], $titles);
        self::assertSame('Superbe lumière !', FakePushSender::$sent[1]['payload']['body']);
        self::assertStringStartsWith('/p/'.$post->getPublicId().'#comment-', FakePushSender::$sent[1]['payload']['url']);
        $endpoints = array_map(static fn (PushSubscription $s): string => $s->getEndpoint(), self::getContainer()->get(PushSubscriptionRepository::class)->findAll());
        self::assertSame(['https://push.example/telephone-camille'], $endpoints, 'Appareil dont l’abonnement n’existe plus : oublié.');

        // Désabonnement de l'appareil, et jeton exigé.
        $this->client->request('POST', '/notifications/appareils/retirer', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) json_encode(['endpoint' => 'https://push.example/telephone-camille']));
        self::assertResponseIsSuccessful();
        self::assertSame([], self::getContainer()->get(PushSubscriptionRepository::class)->findAll());
        $this->device('mauvais-jeton', 'https://push.example/telephone-camille');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Notifications envoyées (relues à chaque appel).
     *
     * @return list<array{endpoint: string, payload: array{title: string, body: string, url: string, tag: string}}>
     *
     * @phpstan-impure
     */
    private function sent(): array
    {
        return FakePushSender::$sent;
    }

    /**
     * @return array{title: string, body: string, url: string, tag: string}
     */
    private function lastSent(): array
    {
        $sent = $this->sent();
        self::assertNotSame([], $sent);

        return array_last($sent)['payload'];
    }

    private function device(string $token, string $endpoint): void
    {
        $this->client->request('POST', '/notifications/appareils', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) json_encode([
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
        ]));
    }

    private function account(\App\Core\Domain\Account\User $user): Account
    {
        $fresh = self::getContainer()->get('doctrine')->getRepository(Account::class)->find($user->getAccount()->getId());
        self::assertInstanceOf(Account::class, $fresh);

        return $fresh;
    }

    private function post(Post $post): Post
    {
        $fresh = self::getContainer()->get(PostRepository::class)->find($post->getId());
        self::assertInstanceOf(Post::class, $fresh);

        return $fresh;
    }
}
