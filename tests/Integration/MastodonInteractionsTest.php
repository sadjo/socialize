<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Notification\NotificationType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\Comment;
use App\Core\Domain\Social\Follow;
use App\Core\Domain\Social\FollowState;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\NotificationRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Application\ApUrls;
use App\Federation\Application\InboxProcessor;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use App\Tests\Federation\FakeFediverse;

/**
 * J'aime, commentaires et abonnements d'un compte Mastodon connecté : par l'API de son serveur, enregistrés ici
 * aussitôt, sans doublon quand l'activité fédérée arrive ensuite.
 */
final class MastodonInteractionsTest extends AppWebTestCase
{
    use ConnectsWithMastodon;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
        FakeFediverse::$outbox = [];
    }

    public function testALikeGoesThroughTheMastodonServer(): void
    {
        $post = $this->publish('Falaise', Visibility::Public);
        $note = self::getContainer()->get(ApUrls::class)->note($post);
        $this->connect();

        $this->toggleLike($post);
        $favourite = FakeMastodon::requestsTo('POST', '/api/v1/statuses/'.FakeMastodon::idOf($note).'/favourite');
        self::assertCount(1, $favourite, 'La publication est retrouvée sur son serveur par son adresse, puis aimée.');
        self::assertSame(FakeMastodon::TOKEN, $favourite[0]['token']);
        self::assertSame(1, $this->reload($post)->getLikesCount());

        // Le J'aime fédéré arrive ensuite : pas de doublon.
        self::getContainer()->get(InboxProcessor::class)->process(['type' => 'Like', 'id' => 'https://remote.test/likes/1', 'actor' => FakeFediverse::ALICE, 'object' => $note], $this->alice());
        self::assertSame(1, $this->reload($post)->getLikesCount());

        $this->toggleLike($post);
        self::assertCount(1, FakeMastodon::requestsTo('POST', '/api/v1/statuses/'.FakeMastodon::idOf($note).'/unfavourite'));
        self::assertSame(0, $this->reload($post)->getLikesCount());

        // Son serveur ne retrouve pas la publication : rien ne change, et la raison est dite.
        FakeMastodon::$searchFails = true;
        $this->toggleLike($post);
        self::assertSame(0, $this->reload($post)->getLikesCount());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'remote.test ne trouve pas cette publication');
    }

    public function testACommentIsAReplyOnTheMastodonServer(): void
    {
        $post = $this->publish('Port', Visibility::Public);
        $note = self::getContainer()->get(ApUrls::class)->note($post);
        $this->connect();

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('#post-'.$post->getPublicId().'-comment-form')->form(), ['content' => 'Superbe lumière !']);
        $status = FakeMastodon::requestsTo('POST', '/api/v1/statuses')[0]['body'] ?? [];
        self::assertSame('@camille@localhost Superbe lumière !', $status['status'] ?? null, 'Réponse qui mentionne l’auteur, comme une application Mastodon.');
        self::assertSame(FakeMastodon::idOf($note), $status['in_reply_to_id'] ?? null);
        self::assertSame('public', $status['visibility'] ?? null);

        $comments = self::getContainer()->get(CommentRepository::class)->findBy(['post' => $post]);
        self::assertCount(1, $comments);
        self::assertSame('Superbe lumière !', $comments[0]->getContent());
        self::assertSame('https://remote.test/users/alice/statuses/100', $comments[0]->getApUri());

        // La réponse fédérée arrive ensuite : reconnue à son adresse.
        self::getContainer()->get(InboxProcessor::class)->process(['type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => [
            'id' => 'https://remote.test/users/alice/statuses/100', 'type' => 'Note', 'attributedTo' => FakeFediverse::ALICE,
            'content' => '<p>@camille Superbe lumière !</p>', 'inReplyTo' => $note, 'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ]], $this->alice());
        self::assertSame(1, $this->reload($post)->getCommentsCount());

        // Suppression par son autrice : sur son serveur aussi.
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('form[action="/c/'.$comments[0]->getPublicId().'/supprimer"]')->form());
        self::assertCount(1, FakeMastodon::requestsTo('DELETE', '/api/v1/statuses/'.FakeMastodon::idOf('https://remote.test/users/alice/statuses/100')));
        self::assertSame(0, $this->reload($post)->getCommentsCount());
    }

    public function testACommentOnAFollowersOnlyPostIsADirectMessageToItsAuthor(): void
    {
        $post = $this->publish('Pour mes abonnés', Visibility::Followers);
        $this->connect();
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist(new Follow($this->alice(), $this->camille()));
        $em->flush();

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('#post-'.$post->getPublicId().'-comment-form')->form(), ['content' => 'Merci']);
        self::assertSame('direct', FakeMastodon::requestsTo('POST', '/api/v1/statuses')[0]['body']['visibility'] ?? null, 'Rien n’est montré aux abonnés d’alice.');
    }

    /**
     * Publication d'un groupe (restée sur l'instance) : un compte Mastodon membre du groupe la voit, et son J'aime
     * comme son commentaire restent ici, sans passer par son serveur, qui ne la connaît pas.
     */
    public function testAGroupMemberConnectedWithMastodonSeesAndInteractsWithAGroupPost(): void
    {
        $this->connect();
        $this->register('camille');
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist(new Follow($this->alice(), $this->camille()));
        $em->flush();
        $groups = self::getContainer()->get(\App\Core\Application\Social\AudienceGroups::class);
        $family = $groups->create($this->camille(), 'Famille');
        $post = self::getContainer()->get(PostComposer::class)->publish($this->camille(), 'En famille', [['file' => $this->jpeg(200, 250), 'alt' => null]], Visibility::Groups, audienceGroups: [$family]);

        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertResponseStatusCodeSame(404, 'Pas encore membre du groupe.');
        // Services repris après la requête du client (qui relance le noyau).
        self::getContainer()->get(\App\Core\Application\Social\AudienceGroups::class)->setMembers(self::getContainer()->get(\App\Core\Infrastructure\Doctrine\AudienceGroupRepository::class)->find($family->getId()) ?? throw new \LogicException(), [$this->alice()->getId()->toRfc4122()]);
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertResponseIsSuccessful();

        $this->toggleLike($post);
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('#post-'.$post->getPublicId().'-comment-form')->form(), ['content' => 'Belle journée']);
        self::assertSame(1, $this->reload($post)->getLikesCount());
        self::assertSame(1, $this->reload($post)->getCommentsCount());
        self::assertSame([], array_filter(FakeMastodon::$requests, static fn (array $r): bool => str_contains($r['url'], '/statuses') || str_contains($r['url'], '/search')), 'Rien ne passe par son serveur.');
        $comment = self::getContainer()->get(CommentRepository::class)->findOneBy(['post' => $post->getId()]);
        self::assertInstanceOf(Comment::class, $comment);
        self::assertNull($comment->getApUri());
    }

    public function testFollowingGoesThroughTheMastodonServer(): void
    {
        $this->publish('Bonjour', Visibility::Public);
        $this->connect();
        $actor = self::getContainer()->get(ApUrls::class)->actor($this->camille());

        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Suivre')->form());
        self::assertCount(1, FakeMastodon::requestsTo('POST', '/api/v1/accounts/'.FakeMastodon::idOf($actor).'/follow'));
        $follow = self::getContainer()->get(FollowRepository::class)->findBetween($this->alice(), $this->camille());
        self::assertInstanceOf(Follow::class, $follow);
        self::assertSame(FollowState::Accepted, $follow->getState(), 'Un compte d’ici accepte tout abonnement.');

        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Abonné · Ne plus suivre')->form());
        self::assertCount(1, FakeMastodon::requestsTo('POST', '/api/v1/accounts/'.FakeMastodon::idOf($actor).'/unfollow'));
        self::assertNull(self::getContainer()->get(FollowRepository::class)->findBetween($this->alice(), $this->camille()));
    }

    /**
     * Compte d'ici qui valide lui-même ses abonnés : l'abonnement d'un compte Mastodon connecté ici attend son accord,
     * comme celui d'un compte d'ici (notification de la demande), et l'Accept ne part qu'à son acceptation.
     */
    public function testFollowingAnAccountThatApprovesItsFollowersWaitsForItsApproval(): void
    {
        $this->publish('Bonjour', Visibility::Public);
        $camille = $this->camille();
        $camille->requireFollowApproval(true);
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->connect();

        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Suivre')->form());
        $follow = self::getContainer()->get(FollowRepository::class)->findBetween($this->alice(), $this->camille());
        self::assertInstanceOf(Follow::class, $follow);
        self::assertSame(FollowState::Pending, $follow->getState(), 'En attente de l’accord de camille.');
        self::assertSame(1, self::getContainer()->get(NotificationRepository::class)->count(['recipient' => $this->camille(), 'type' => NotificationType::FollowRequest]));

        // L'activité Follow fédérée par son serveur arrive ensuite : la demande reste en attente, sans Accept.
        $actor = self::getContainer()->get(ApUrls::class)->actor($this->camille());
        self::getContainer()->get(InboxProcessor::class)->process([
            'id' => 'https://remote.test/users/alice#follows/1', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => $actor,
        ], $this->alice());
        self::assertFalse(self::getContainer()->get(FollowRepository::class)->findBetween($this->alice(), $this->camille())?->isAccepted());
        $fediverse = self::getContainer()->get(FakeFediverse::class);
        self::assertInstanceOf(FakeFediverse::class, $fediverse);
        self::assertSame([], $fediverse->delivered('Accept'), 'Pas d’Accept avant son accord.');
    }

    private function publish(string $caption, Visibility $visibility): Post
    {
        $camille = $this->register('camille');

        return self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), $caption, [['file' => $this->jpeg(200, 250), 'alt' => null]], $visibility);
    }

    private function toggleLike(Post $post): void
    {
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('form[action="/p/'.$post->getPublicId().'/aimer"]')->form());
    }

    private function reload(Post $post): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(PostRepository::class)->find($post->getId());
        self::assertInstanceOf(Post::class, $fresh);

        return $fresh;
    }

    private function alice(): Account
    {
        $alice = self::getContainer()->get(AccountRepository::class)->findOneBy(['username' => 'alice', 'domain' => 'remote.test']);
        self::assertInstanceOf(Account::class, $alice);

        return $alice;
    }

    private function camille(): Account
    {
        $camille = self::getContainer()->get(AccountRepository::class)->findLocal('camille');
        self::assertInstanceOf(Account::class, $camille);

        return $camille;
    }
}
