<?php

declare(strict_types=1);

namespace App\Tests\Federation;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Infrastructure\HttpSignature;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

final class FederationTest extends AppWebTestCase
{
    private const string AP = 'application/activity+json';

    public function testWebFingerActorNodeInfoAndNote(): void
    {
        $camille = $this->register('camille')->getAccount();
        $post = $this->publish($camille, 'Lac Blanc #montagne', Visibility::Public);
        $private = $this->publish($camille, 'Juste pour moi', Visibility::Private);

        $this->client->request('GET', '/.well-known/webfinger?resource=acct:camille@localhost');
        self::assertResponseIsSuccessful();
        $jrd = $this->json();
        self::assertSame('acct:camille@localhost', $jrd['subject']);
        self::assertSame('http://localhost/users/camille', $this->dig($jrd, 'links', 0, 'href'));

        $this->client->request('GET', '/.well-known/webfinger?resource=acct:personne@localhost');
        self::assertResponseStatusCodeSame(404);

        // Acteur d'instance : Mastodon le résout par WebFinger avant d'accepter nos lectures signées.
        $this->client->request('GET', '/.well-known/webfinger?resource=acct:localhost@localhost');
        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost/actor', self::dig($this->json(), 'links', 0, 'href'));
        $this->client->request('GET', '/actor', server: ['HTTP_ACCEPT' => self::AP]);
        self::assertSame('localhost', $this->json()['preferredUsername']);

        $this->client->request('GET', '/users/camille', server: ['HTTP_ACCEPT' => self::AP]);
        $actor = $this->json();
        self::assertSame('Person', $actor['type']);
        self::assertSame('http://localhost/users/camille/inbox', $actor['inbox']);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $this->str($actor, 'publicKey', 'publicKeyPem'));

        $this->client->request('GET', '/users/camille');
        self::assertResponseRedirects('http://localhost/@camille', message: 'Un navigateur est redirigé vers le profil.');

        $this->client->request('GET', '/nodeinfo/2.1');
        self::assertSame(['activitypub'], $this->json()['protocols']);

        $this->client->request('GET', '/p/'.$post->getPublicId(), server: ['HTTP_ACCEPT' => self::AP]);
        $note = $this->json();
        self::assertSame('Note', $note['type']);
        self::assertSame(['https://www.w3.org/ns/activitystreams#Public'], $note['to']);
        self::assertSame('Image', $this->dig($note, 'attachment', 0, 'type'));
        self::assertStringStartsWith('http://localhost/m/', $this->str($note, 'attachment', 0, 'url'));
        self::assertSame('#montagne', $this->dig($note, 'tag', 0, 'name'));

        $this->client->request('GET', '/p/'.$private->getPublicId(), server: ['HTTP_ACCEPT' => self::AP]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/users/camille/outbox?page=1', server: ['HTTP_ACCEPT' => self::AP]);
        self::assertCount(1, $this->list($this->json(), 'orderedItems'), 'La boîte d’envoi ne montre que les publications publiques.');
    }

    public function testARemoteFollowIsAcceptedAndReceivesNewPosts(): void
    {
        $camille = $this->register('camille')->getAccount();

        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/1', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        self::assertResponseStatusCodeSame(202);

        $accept = $this->fediverse()->delivered('Accept');
        self::assertCount(1, $accept);
        self::assertSame(FakeFediverse::ALICE.'#follows/1', $this->dig($accept, 0, 'object', 'id'));
        $this->assertSignedByLocalAccount($this->fediverse()->deliveries[0], 'camille');

        $alice = $this->alice();
        self::assertTrue(self::getContainer()->get(FollowRepository::class)->isFollowing($alice, $this->reloadAccount($camille)));
        self::assertSame('Alice Martin', $alice->getDisplayName());
        self::assertSame('https://remote.test/inbox', $alice->getDeliveryInbox());

        $this->publish($this->reloadAccount($camille), 'Nouvelle photo', Visibility::Public);
        $create = $this->fediverse()->delivered('Create');
        self::assertCount(1, $create);
        self::assertSame('https://remote.test/inbox', $this->dig($this->fediverse()->deliveries, \count($this->fediverse()->deliveries) - 1, 'url'));
        self::assertSame('Note', $this->dig($create, 0, 'object', 'type'));
        self::assertStringContainsString('Nouvelle photo', $this->str($create, 0, 'object', 'content'));

        $this->publish($this->reloadAccount($camille), 'Privé', Visibility::Private);
        self::assertCount(1, $this->fediverse()->delivered('Create'), 'Une publication privée ne part pas dans le fédivers.');
    }

    public function testEditingAPostIsFederated(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/9', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $post = $this->publish($this->reloadAccount($camille), 'Avant', Visibility::Public);
        $editor = self::getContainer()->get(\App\Media\Application\PostEditor::class);
        $order = array_values(array_map(\App\Media\Application\PostEditor::token(...), $post->getMedia()->toArray()));

        $editor->edit($post, 'Après', Visibility::Public, null, null, $order);
        $update = $this->fediverse()->delivered('Update');
        self::assertCount(1, $update);
        self::assertSame('Note', self::dig($update, 0, 'object', 'type'));
        self::assertStringContainsString('Après', self::str($update, 0, 'object', 'content'));
        self::assertIsString(self::dig($update, 0, 'object', 'updated'));

        $editor->edit($post, 'Après', Visibility::Private, null, null, $order);
        self::assertCount(1, $this->fediverse()->delivered('Delete'), 'Une publication passée en privé est retirée du fédivers.');

        $editor->edit($post, 'Après', Visibility::Followers, null, null, $order);
        self::assertCount(2, $this->fediverse()->delivered('Create'), 'Elle y revient quand elle redevient visible.');
    }

    public function testUnsignedOrForgedActivitiesAreRejected(): void
    {
        $this->register('camille');
        $body = (string) json_encode(['id' => FakeFediverse::ALICE.'#follows/2', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille'], \JSON_UNESCAPED_SLASHES);

        $this->client->request('POST', '/inbox', server: ['CONTENT_TYPE' => self::AP], content: $body);
        self::assertResponseStatusCodeSame(401);

        $server = FakeFediverse::signedServer('http://localhost/inbox', $body);
        $this->client->request('POST', '/inbox', server: $server, content: str_replace('follows/2', 'follows/3', $body));
        self::assertResponseStatusCodeSame(401, 'Un corps modifié après signature est refusé (Digest).');

        $impersonation = (string) json_encode(['id' => 'https://evil.test/x', 'type' => 'Follow', 'actor' => 'https://evil.test/users/mallory', 'object' => 'http://localhost/users/camille']);
        $this->client->request('POST', '/inbox', server: FakeFediverse::signedServer('http://localhost/inbox', $impersonation), content: $impersonation);
        self::assertResponseStatusCodeSame(401, 'Une activité au nom d’un autre acteur est refusée.');

        self::assertSame(0, self::getContainer()->get(FollowRepository::class)->count([]));
    }

    public function testFollowARemoteAccountThenReceiveItsPhotos(): void
    {
        $this->login($this->register('camille'));

        $this->client->request('GET', '/recherche?q=@alice@remote.test');
        self::assertResponseRedirects('/@alice@remote.test');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Alice Martin');

        $this->client->submit($crawler->selectButton('Suivre')->form());
        $follow = $this->fediverse()->delivered('Follow');
        self::assertCount(1, $follow);
        self::assertSame(FakeFediverse::ALICE, $follow[0]['object']);
        self::assertSame(FakeFediverse::ALICE.'/inbox', $this->fediverse()->deliveries[0]['url']);

        $this->client->request('GET', '/@alice@remote.test');
        self::assertSelectorTextContains('.prof-i', 'Demande envoyée');

        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#accepts/1', 'type' => 'Accept', 'actor' => FakeFediverse::ALICE, 'object' => $follow[0]]);
        self::assertResponseStatusCodeSame(202);

        $note = $this->remoteNote('https://remote.test/notes/1', 'Voie lactée au Pic du Midi <a href="https://remote.test/tags/astro">#astro</a>');
        $this->inbox('/inbox', ['id' => 'https://remote.test/notes/1/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => $note]);
        self::assertResponseStatusCodeSame(202);

        $post = self::getContainer()->get(PostRepository::class)->findOneBy(['apUri' => 'https://remote.test/notes/1']);
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('Voie lactée au Pic du Midi #astro', $post->getCaption());

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('article.post', 'Voie lactée');
        self::assertSelectorExists('article.post img[src="https://remote.test/media/1.jpg"][alt="Ciel étoilé"]');
        self::assertSelectorTextContains('article.post .fed', 'remote.test');
        $this->client->request('GET', '/federe');
        self::assertSelectorCount(1, 'article.post');

        // Like et commentaire envoyés à l'autrice
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('form[action$="/aimer"]')->form());
        $like = $this->fediverse()->delivered('Like');
        self::assertSame('https://remote.test/notes/1', $like[0]['object']);

        $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submitForm('Publier', ['comment[content]' => 'Magnifique !']);
        $reply = $this->fediverse()->delivered('Create');
        self::assertSame('https://remote.test/notes/1', $this->dig($reply, 0, 'object', 'inReplyTo'));

        // Suppression par l'autrice
        $this->inbox('/inbox', ['id' => 'https://remote.test/notes/1#delete', 'type' => 'Delete', 'actor' => FakeFediverse::ALICE, 'object' => ['id' => 'https://remote.test/notes/1', 'type' => 'Tombstone']]);
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNull(self::getContainer()->get(PostRepository::class)->findOneBy(['apUri' => 'https://remote.test/notes/1']));
    }

    public function testRemoteLikesAndRepliesOnALocalPost(): void
    {
        $camille = $this->register('camille')->getAccount();
        $post = $this->publish($camille, 'Étretat', Visibility::Public);
        $url = 'http://localhost/p/'.$post->getPublicId();

        $this->inbox('/inbox', ['id' => FakeFediverse::ALICE.'#likes/1', 'type' => 'Like', 'actor' => FakeFediverse::ALICE, 'object' => $url]);
        self::assertSame(1, $this->reloadPost($post)->getLikesCount());

        $this->inbox('/inbox', ['id' => FakeFediverse::ALICE.'#undo/1', 'type' => 'Undo', 'actor' => FakeFediverse::ALICE, 'object' => ['id' => FakeFediverse::ALICE.'#likes/1', 'type' => 'Like', 'actor' => FakeFediverse::ALICE, 'object' => $url]]);
        self::assertSame(0, $this->reloadPost($post)->getLikesCount());

        $reply = $this->remoteNote('https://remote.test/notes/2', '<p><span class="h-card"><a href="http://localhost/@camille">@camille</a></span> Superbe lumière</p>', $url, withImage: false);
        $this->inbox('/inbox', ['id' => 'https://remote.test/notes/2/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => $reply]);
        self::assertSame(1, $this->reloadPost($post)->getCommentsCount());

        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorTextContains('.cmts', 'Superbe lumière');
        self::assertSelectorTextContains('.cmts', '@remote.test');
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function inbox(string $path, array $activity): void
    {
        $body = (string) json_encode($activity, \JSON_UNESCAPED_SLASHES);
        $this->client->request('POST', $path, server: FakeFediverse::signedServer('http://localhost'.$path, $body), content: $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteNote(string $id, string $content, ?string $inReplyTo = null, bool $withImage = true): array
    {
        return array_filter([
            'id' => $id,
            'type' => 'Note',
            'attributedTo' => FakeFediverse::ALICE,
            'content' => $content,
            'inReplyTo' => $inReplyTo,
            'published' => '2026-11-12T08:00:00Z',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [FakeFediverse::ALICE.'/followers'],
            'attachment' => $withImage ? [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://remote.test/media/1.jpg', 'name' => 'Ciel étoilé', 'width' => 1080, 'height' => 1350]] : [],
        ], static fn (mixed $v): bool => null !== $v);
    }

    /**
     * @param array{url: string, headers: array<string, string>, body: string} $delivery
     */
    private function assertSignedByLocalAccount(array $delivery, string $username): void
    {
        $account = $this->reloadAccount(self::getContainer()->get(AccountRepository::class)->findLocal($username));
        self::assertStringContainsString('keyId="http://localhost/users/'.$username.'#main-key"', $delivery['headers']['signature']);
        HttpSignature::verify('POST', (string) parse_url($delivery['url'], \PHP_URL_PATH), $delivery['headers'], $delivery['body'], (string) $account->getPublicKeyPem());
    }

    private function publish(Account $author, string $caption, Visibility $visibility): Post
    {
        return self::getContainer()->get(PostComposer::class)->publish($author, $caption, [['file' => $this->jpeg(200, 250), 'alt' => null]], $visibility);
    }

    private function fediverse(): FakeFediverse
    {
        $fediverse = self::getContainer()->get(FakeFediverse::class);
        self::assertInstanceOf(FakeFediverse::class, $fediverse);

        return $fediverse;
    }

    /**
     * Valeur au bout d'un chemin dans un document JSON décodé.
     */
    private function dig(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }

        return $data;
    }

    private function str(mixed $data, string|int ...$path): string
    {
        $value = $this->dig($data, ...$path);
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private function list(mixed $data, string|int ...$path): array
    {
        $value = $this->dig($data, ...$path);
        self::assertIsArray($value);

        return $value;
    }

    private function alice(): Account
    {
        $alice = self::getContainer()->get(AccountRepository::class)->findOneBy(['apUri' => FakeFediverse::ALICE]);
        self::assertInstanceOf(Account::class, $alice);

        return $alice;
    }

    private function reloadAccount(?Account $account): Account
    {
        self::assertNotNull($account);
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(AccountRepository::class)->find($account->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function reloadPost(Post $post): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(PostRepository::class)->find($post->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }
}
