<?php

declare(strict_types=1);

namespace App\Tests\Federation;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Infrastructure\HttpSignature;
use App\Media\Application\MediaUrls;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
        self::assertSame('http://localhost/actor/outbox', $this->json()['outbox']);
        $this->client->request('GET', '/actor/outbox', server: ['HTTP_ACCEPT' => self::AP]);
        self::assertResponseIsSuccessful();
        self::assertSame(['type' => 'OrderedCollection', 'totalItems' => 0], array_intersect_key($this->json(), ['type' => 1, 'totalItems' => 1]), 'Boîte d’envoi de l’instance, vide.');

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

    /**
     * Publication texte d'un compte d'ici : une Note sans pièce jointe, comme un message Mastodon.
     */
    public function testALocalTextPostIsDeliveredAsANoteWithoutAttachment(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/1', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);

        self::getContainer()->get(PostComposer::class)->publish($this->reloadAccount($camille), 'Un texte sans photo', []);

        $create = $this->fediverse()->delivered('Create');
        self::assertCount(1, $create);
        self::assertSame('Note', $this->dig($create, 0, 'object', 'type'));
        self::assertSame([], $this->dig($create, 0, 'object', 'attachment'));
        $content = $this->dig($create, 0, 'object', 'content');
        self::assertIsString($content);
        self::assertStringContainsString('Un texte sans photo', $content);
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

    public function testMentionsAreTaggedAndDeliveredEvenToUnknownAccounts(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->register('hugo');

        // Alice n'est pas encore connue de l'instance ni abonnée : trouvée par WebFinger, puis livrée.
        $post = $this->publish($camille, 'Avec @alice@remote.test, @hugo et ig:@natgeo', Visibility::Public);
        $create = $this->fediverse()->delivered('Create');
        self::assertCount(1, $create, 'Livrée au compte mentionné.');
        self::assertSame('https://remote.test/inbox', $this->dig($this->fediverse()->deliveries, \count($this->fediverse()->deliveries) - 1, 'url'));

        $this->client->request('GET', '/p/'.$post->getPublicId(), server: ['HTTP_ACCEPT' => self::AP]);
        $note = $this->json();
        self::assertIsArray($note['cc']);
        self::assertContains(FakeFediverse::ALICE, $note['cc']);
        self::assertContains('http://localhost/users/hugo', $note['cc']);
        $mentions = array_values(array_filter((array) $note['tag'], static fn (mixed $t): bool => \is_array($t) && 'Mention' === ($t['type'] ?? null)));
        self::assertSame([
            ['type' => 'Mention', 'href' => FakeFediverse::ALICE, 'name' => '@alice@remote.test'],
            ['type' => 'Mention', 'href' => 'http://localhost/users/hugo', 'name' => '@hugo@localhost'],
        ], $mentions);
        $content = $this->str($note, 'content');
        self::assertStringContainsString('<a href="https://www.instagram.com/natgeo/" rel="nofollow noopener" target="_blank">@natgeo (Instagram)</a>', $content);
        self::assertStringNotContainsString('ig:', $content);

        $this->publish($this->reloadAccount($camille), 'Pour @alice@remote.test seulement', Visibility::Private);
        self::assertCount(1, $this->fediverse()->delivered('Create'), 'Une publication privée ne part pas, même mentionnée.');
    }

    public function testWebAddressesAreLinkedLikeMastodonDoes(): void
    {
        $camille = $this->register('camille')->getAccount();
        $post = $this->publish($camille, 'Le programme : https://www.exemple.fr/agenda/2026/festival-photo-de-novembre #expo', Visibility::Public);
        $this->client->request('GET', '/p/'.$post->getPublicId(), server: ['HTTP_ACCEPT' => self::AP]);
        self::assertStringContainsString(
            '<a href="https://www.exemple.fr/agenda/2026/festival-photo-de-novembre" rel="nofollow noopener noreferrer" target="_blank"><span class="invisible">https://www.</span><span class="ellipsis">exemple.fr/agenda/2026/festiva</span><span class="invisible">l-photo-de-novembre</span></a> <a href="http://localhost/tags/expo" class="mention hashtag" rel="tag">#<span>expo</span></a>',
            $this->str($this->json(), 'content'),
            'Adresse web en lien, au format de Mastodon.',
        );
    }

    public function testARemotePostMentioningALocalAccountNotifiesIt(): void
    {
        $camille = $this->register('camille');
        $this->inbox('/inbox', [
            'id' => FakeFediverse::ALICE.'/statuses/9/activity',
            'type' => 'Create',
            'actor' => FakeFediverse::ALICE,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => [
                'id' => FakeFediverse::ALICE.'/statuses/9',
                'type' => 'Note',
                'attributedTo' => FakeFediverse::ALICE,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'content' => '<p>Bravo @camille</p>',
                'published' => '2026-11-17T10:00:00Z',
                'tag' => [['type' => 'Mention', 'href' => 'http://localhost/users/camille', 'name' => '@camille@localhost']],
                'attachment' => [['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://remote.test/media/9.jpg']],
            ],
        ]);
        self::assertResponseStatusCodeSame(202);

        $this->login($camille);
        $this->client->request('GET', '/notifications');
        self::assertSelectorTextContains('.notif', 'vous a mentionné dans sa publication : « Bravo @camille »');
    }

    /**
     * Seules les publications publiques et réservées aux abonnés partent dans le fédivers : non répertoriées ou
     * réservées à des groupes, elles restent sur l'instance, même pour un membre distant du groupe.
     */
    public function testUnlistedAndGroupPostsStayOnTheInstance(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/1', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $groups = self::getContainer()->get(\App\Core\Application\Social\AudienceGroups::class);
        $family = $groups->create($this->reloadAccount($camille), 'Famille');
        $groups->setMembers($family, [$this->alice()->getId()->toRfc4122()]);

        $composer = self::getContainer()->get(PostComposer::class);
        $group = $composer->publish($this->reloadAccount($camille), 'En famille', [['file' => $this->jpeg(200, 250), 'alt' => null]], Visibility::Groups, audienceGroups: [$this->family($family)]);
        $unlisted = $this->publish($this->reloadAccount($camille), 'Avec le lien', Visibility::Unlisted);
        self::assertSame([], $this->fediverse()->delivered('Create'), 'Rien ne part, même vers Alice, membre du groupe.');

        // Ni l'une ni l'autre ne se lit en ActivityPub, ni leurs commentaires ne partent.
        foreach ([$group, $unlisted] as $post) {
            $this->client->request('GET', '/p/'.$post->getPublicId(), server: ['HTTP_ACCEPT' => self::AP]);
            self::assertResponseStatusCodeSame(404);
        }
        $social = self::getContainer()->get(\App\Core\Application\Social\SocialActions::class);
        $post = self::getContainer()->get(PostRepository::class)->find($unlisted->getId());
        self::assertInstanceOf(Post::class, $post);
        $social->comment($this->register('hugo')->getAccount(), $post, 'Joli');
        self::assertSame([], $this->fediverse()->delivered('Create'));

        // Abonnés seulement : envoyée aux abonnés distants.
        $sent = $this->during(fn (): Post => $this->publish($this->reloadAccount($camille), 'Pour mes abonnés', Visibility::Followers));
        self::assertCount(1, $this->ofType($sent, 'Create'));
    }

    /**
     * Mastodon ne change jamais la visibilité ni les destinataires d'une publication déjà reçue : chaque changement
     * la retire (Delete, de tous ses identifiants) puis la renvoie sous un nouvel identifiant (Create).
     */
    public function testChangingWhoCanSeeAPostRemovesItThenSendsItUnderANewId(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/7', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $groups = self::getContainer()->get(\App\Core\Application\Social\AudienceGroups::class);
        $family = $groups->create($this->reloadAccount($camille), 'Famille');
        $groups->setMembers($family, [$this->alice()->getId()->toRfc4122()]);
        $post = $this->publish($this->reloadAccount($camille), 'Concert', Visibility::Public);
        $family = self::getContainer()->get(\App\Core\Infrastructure\Doctrine\AudienceGroupRepository::class)->find($family->getId());
        self::assertNotNull($family);
        $noteId = 'http://localhost/p/'.$post->getPublicId();
        $editor = self::getContainer()->get(\App\Media\Application\PostEditor::class);
        $order = array_values(array_map(\App\Media\Application\PostEditor::token(...), $post->getMedia()->toArray()));
        $public = 'https://www.w3.org/ns/activitystreams#Public';

        // Public → abonnés : retirée, puis renvoyée réservée aux abonnés.
        $sent = $this->during(static fn () => $editor->edit($post, 'Concert', Visibility::Followers, null, null, $order));
        self::assertSame([$noteId], $this->objectIds($sent, 'Delete'));
        self::assertSame([$noteId.'/v2'], $this->objectIds($sent, 'Create'));
        $create = $this->ofType($sent, 'Create')[0];
        self::assertNotContains($public, [...(array) $this->dig($create, 'object', 'to'), ...(array) $this->dig($create, 'object', 'cc')], 'Plus publique.');
        self::assertSame([], $this->ofType($sent, 'Update'), 'Un Update ne changerait pas la visibilité chez Mastodon.');

        // Simple retouche du texte : même visibilité, mêmes destinataires, Update sous le même identifiant.
        $sent = $this->during(static fn () => $editor->edit($post, 'Concert de rock', Visibility::Followers, null, null, $order));
        self::assertSame([$noteId.'/v2'], $this->objectIds($sent, 'Update'));
        self::assertSame([], $this->ofType($sent, 'Delete'));

        // Abonnés → groupe Famille : retirée de partout (tous ses identifiants), et pas renvoyée (les groupes restent ici).
        $sent = $this->during(static fn () => $editor->edit($post, 'Concert de rock', Visibility::Groups, null, null, $order, audienceGroups: [$family]));
        self::assertSame([$noteId, $noteId.'/v2'], $this->objectIds($sent, 'Delete'));
        self::assertSame([], $this->ofType($sent, 'Create'));

        // Groupe → non répertoriée → privée : rien ne part, rien n'est retiré (elle n'est plus dans le fédivers).
        $sent = $this->during(static fn () => $editor->edit($post, 'Concert de rock', Visibility::Unlisted, null, null, $order));
        $sent = [...$sent, ...$this->during(static fn () => $editor->edit($post, 'Concert de rock', Visibility::Private, null, null, $order))];
        self::assertSame([], $sent);

        // Privée → publique : renvoyée sous un nouvel identifiant, sans retrait.
        $sent = $this->during(static fn () => $editor->edit($post, 'Concert de rock', Visibility::Public, null, null, $order));
        self::assertSame([$noteId.'/v3'], $this->objectIds($sent, 'Create'));
        self::assertSame([], $this->ofType($sent, 'Delete'));
        self::assertContains($public, (array) $this->dig($this->ofType($sent, 'Create')[0], 'object', 'to'));

        // Suppression : retrait de tous les identifiants qu'elle a eus.
        $sent = $this->during(static fn () => self::getContainer()->get(PostComposer::class)->delete($post));
        self::assertSame([$noteId, $noteId.'/v2', $noteId.'/v3'], $this->objectIds($sent, 'Delete'));
    }

    /**
     * Date de publication changée par un administrateur : Mastodon garde la date d'un statut reçu, la publication
     * est donc retirée puis renvoyée à sa nouvelle date, sous un nouvel identifiant.
     */
    public function testRedatingAPostSendsItAgainAtItsNewDate(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/8', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $post = $this->publish($this->reloadAccount($camille), 'Souvenir', Visibility::Public);
        $noteId = 'http://localhost/p/'.$post->getPublicId();
        $editor = self::getContainer()->get(\App\Media\Application\PostEditor::class);
        $order = array_values(array_map(\App\Media\Application\PostEditor::token(...), $post->getMedia()->toArray()));

        $date = new \DateTimeImmutable('2025-07-14 18:30:00 UTC');
        $sent = $this->during(static fn () => $editor->edit($post, 'Souvenir', Visibility::Public, null, null, $order, publishedAt: $date));
        self::assertSame([$noteId], $this->objectIds($sent, 'Delete'));
        self::assertSame([$noteId.'/v2'], $this->objectIds($sent, 'Create'));
        self::assertSame([], $this->ofType($sent, 'Update'), 'Un Update ne changerait pas la date chez Mastodon.');
        self::assertSame('2025-07-14T18:30:00+00:00', $this->dig($this->ofType($sent, 'Create')[0], 'object', 'published'));

        // Même date : simple mise à jour.
        $sent = $this->during(static fn () => $editor->edit($post, 'Souvenir d’été', Visibility::Public, null, null, $order, publishedAt: $date));
        self::assertSame([$noteId.'/v2'], $this->objectIds($sent, 'Update'));
        self::assertSame([], $this->ofType($sent, 'Delete'));
    }

    /**
     * Remise en ordre : les publications visibles dans le fédivers y sont retirées puis renvoyées de la plus ancienne
     * à la plus récente ; les publications importées sans annonce n'y partent pas.
     */
    public function testTheReorderCommandSendsPostsAgainFromTheOldest(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/9', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $recent = $this->publish($this->reloadAccount($camille), 'Récente', Visibility::Public);
        $old = $this->publish($this->reloadAccount($camille), 'Ancienne', Visibility::Public);
        $private = $this->publish($this->reloadAccount($camille), 'Privée', Visibility::Private);
        $import = $this->publish($this->reloadAccount($camille), 'Import discret', Visibility::Public);
        $em = self::getContainer()->get('doctrine')->getManager();
        foreach ([[$recent, '2025-10-01'], [$old, '2025-09-05'], [$private, '2025-09-20'], [$import, '2025-09-10']] as [$p, $date]) {
            $p = $this->reloadPost($p);
            $p->redate(new \DateTimeImmutable($date.' 10:00 UTC'));
            if ($p->getId()->equals($import->getId())) {
                new \ReflectionProperty(Post::class, 'announce')->setValue($p, false);
            }
            $em->flush();
        }
        $command = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find('app:federation:reorder'));

        // Essai : la liste, sans rien envoyer.
        $sent = $this->during(static fn (): int => $command->execute(['compte' => 'camille', '--essai' => true]));
        $command->assertCommandIsSuccessful();
        self::assertSame([], $sent);
        self::assertStringContainsString('2 publications, du 05/09/2025 au 01/10/2025', $command->getDisplay());

        $sent = $this->during(static fn (): int => $command->execute(['compte' => 'camille', '--pause' => '0']));
        $command->assertCommandIsSuccessful();
        $old = 'http://localhost/p/'.$old->getPublicId();
        $recent = 'http://localhost/p/'.$recent->getPublicId();
        self::assertSame([$old.'/v2', $recent.'/v2'], $this->objectIds($sent, 'Create'), 'De la plus ancienne à la plus récente.');
        self::assertSame([$old, $recent], $this->objectIds($sent, 'Delete'));

        // Depuis une date : seulement les publications parues depuis.
        $sent = $this->during(static fn (): int => $command->execute(['compte' => 'camille', '--depuis' => '2025-09-15', '--pause' => '0']));
        self::assertSame([$recent.'/v3'], $this->objectIds($sent, 'Create'));
        // Resync d'une publication importée sans annonce : retirée, jamais envoyée.
        $resync = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find('app:federation:resync'));
        $sent = $this->during(static fn (): int => $resync->execute(['publication' => $import->getPublicId()]));
        self::assertSame([], $this->ofType($sent, 'Create'));
    }

    public function testTheRefreshCommandUpdatesPostsWithLinksUnderTheirId(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/9', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $linked = $this->publish($this->reloadAccount($camille), 'Programme sur www.exemple.fr', Visibility::Public);
        $plain = $this->publish($this->reloadAccount($camille), 'Sans lien', Visibility::Public);
        $this->publish($this->reloadAccount($camille), 'Privée : exemple.fr', Visibility::Private);
        $command = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find('app:federation:refresh'));

        $sent = $this->during(static fn (): int => $command->execute(['compte' => 'camille', '--essai' => true]));
        $command->assertCommandIsSuccessful();
        self::assertSame([], $sent);
        self::assertStringContainsString('1 publication à mettre à jour', $command->getDisplay());

        // Par défaut : seulement les publications avec une adresse web, sous leur identifiant, sans retrait.
        $linkedId = 'http://localhost/p/'.$linked->getPublicId();
        $sent = $this->during(static fn (): int => $command->execute(['compte' => 'camille', '--pause' => '0']));
        $command->assertCommandIsSuccessful();
        self::assertSame([$linkedId], $this->objectIds($sent, 'Update'));
        self::assertSame([], $this->ofType($sent, 'Delete'));
        self::assertSame([], $this->ofType($sent, 'Create'));
        self::assertStringContainsString('<a href="https://www.exemple.fr"', $this->text($this->ofType($sent, 'Update')[0], 'object', 'content'));

        $sent = $this->during(static fn (): int => $command->execute(['compte' => 'camille', '--toutes' => true, '--pause' => '0']));
        self::assertSame([$linkedId, 'http://localhost/p/'.$plain->getPublicId()], $this->objectIds($sent, 'Update'));

        $sent = $this->during(static fn (): int => $command->execute(['compte' => 'camille', '--publications' => $plain->getPublicId(), '--pause' => '0']));
        self::assertSame(['http://localhost/p/'.$plain->getPublicId()], $this->objectIds($sent, 'Update'));
    }

    public function testTheResyncCommandRemovesLeftoverCopies(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/8', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $post = $this->publish($this->reloadAccount($camille), 'Restée sur Mastodon', Visibility::Public);
        $noteId = 'http://localhost/p/'.$post->getPublicId();
        $command = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find('app:federation:resync'));

        // Publication visible : anciennes copies retirées, puis renvoi sous un nouvel identifiant.
        $sent = $this->during(static fn (): int => $command->execute(['publication' => 'https://socialize.example/p/'.$post->getPublicId()]));
        $command->assertCommandIsSuccessful();
        self::assertSame([$noteId], $this->objectIds($sent, 'Delete'));
        self::assertSame([$noteId.'/v2'], $this->objectIds($sent, 'Create'));

        // Publication supprimée : il faut le compte ; tous les identifiants jusqu'à celui de l'adresse sont retirés.
        $publicId = $post->getPublicId();
        $post = self::getContainer()->get(PostRepository::class)->findByPublicId($publicId);
        self::assertInstanceOf(Post::class, $post);
        self::getContainer()->get(PostComposer::class)->delete($post);
        self::assertSame(Command::FAILURE, $command->execute(['publication' => $publicId]));
        self::assertStringContainsString('--compte', $command->getDisplay());
        $sent = $this->during(static fn (): int => $command->execute(['publication' => 'https://socialize.example/p/'.$publicId.'/v3', '--compte' => 'camille']));
        $command->assertCommandIsSuccessful();
        self::assertSame([$noteId, $noteId.'/v2', $noteId.'/v3'], $this->objectIds($sent, 'Delete'));
        self::assertSame(['https://remote.test/inbox'], array_values(array_unique(array_map(fn (array $d): string => $this->text($d, 'url'), $sent))), 'Vers la boîte partagée des abonnés.');

        self::assertSame(Command::FAILURE, $command->execute(['publication' => 'pas une adresse']));
    }

    /**
     * Les publications non répertoriées et celles des groupes partaient auparavant dans le fédivers : la commande
     * app:federation:retract-local-only retire leurs copies, sans toucher aux publications publiques ou aux abonnés.
     */
    public function testTheRetractCommandRemovesLocalOnlyPostsFromTheFediverse(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/11', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $unlisted = $this->publish($this->reloadAccount($camille), 'Avec le lien', Visibility::Unlisted);
        $this->publish($this->reloadAccount($camille), 'Publique', Visibility::Public);
        $command = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find('app:federation:retract-local-only'));

        $sent = $this->during(static fn (): int => $command->execute(['--simulation' => true]));
        $command->assertCommandIsSuccessful();
        self::assertSame([], $sent);
        self::assertStringContainsString('1 publication non répertoriée', $command->getDisplay());

        $noteId = 'http://localhost/p/'.$unlisted->getPublicId();
        $sent = $this->during(static fn (): int => $command->execute([]));
        $command->assertCommandIsSuccessful();
        self::assertSame([$noteId], $this->objectIds($sent, 'Delete'));
        self::assertSame(['https://remote.test/inbox'], array_values(array_unique(array_map(fn (array $d): string => $this->text($d, 'url'), $sent))), 'Chez les abonnés distants.');
        self::assertSame([], $this->ofType($sent, 'Create'), 'Elle n’est pas renvoyée.');
    }

    private function family(\App\Core\Domain\Social\AudienceGroup $group): \App\Core\Domain\Social\AudienceGroup
    {
        $fresh = self::getContainer()->get(\App\Core\Infrastructure\Doctrine\AudienceGroupRepository::class)->find($group->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * Livraisons faites pendant $change.
     *
     * @return list<array<string, mixed>>
     */
    private function during(callable $change): array
    {
        $start = \count($this->fediverse()->deliveries);
        $change();

        return \array_slice($this->fediverse()->deliveries, $start);
    }

    /**
     * @param list<array<string, mixed>> $deliveries
     *
     * @return list<array<string, mixed>>
     */
    private function ofDeliveries(array $deliveries, string $type): array
    {
        return array_values(array_filter($deliveries, fn (array $d): bool => $type === $this->dig($d, 'activity', 'type')));
    }

    /**
     * @param list<array<string, mixed>> $deliveries
     *
     * @return list<array<mixed>> activités
     */
    private function ofType(array $deliveries, string $type): array
    {
        $activities = [];
        foreach ($this->ofDeliveries($deliveries, $type) as $delivery) {
            $activity = $delivery['activity'] ?? null;
            self::assertIsArray($activity);
            $activities[] = $activity;
        }

        return $activities;
    }

    /**
     * Identifiants des objets visés, sans doublon, dans l'ordre d'envoi.
     *
     * @param list<array<string, mixed>> $deliveries
     *
     * @return list<string>
     */
    private function objectIds(array $deliveries, string $type): array
    {
        return array_values(array_unique(array_map(fn (array $a): string => $this->text($a, 'object', 'id'), $this->ofType($deliveries, $type))));
    }

    private function text(mixed $data, string|int ...$path): string
    {
        $value = $this->dig($data, ...$path);
        self::assertIsString($value);

        return $value;
    }

    public function testRemovingARemoteFollowerRejectsItsFollow(): void
    {
        $camille = $this->login($this->register('camille'));
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/1', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);

        $crawler = $this->client->request('GET', '/@camille/abonnes');
        self::assertSelectorTextContains('.netlist', 'Alice Martin');
        $this->client->submit($crawler->selectButton('Retirer')->form());
        self::assertResponseRedirects();

        $reject = $this->fediverse()->delivered('Reject');
        self::assertCount(1, $reject);
        self::assertSame('http://localhost/users/camille', $reject[0]['actor']);
        self::assertSame(FakeFediverse::ALICE.'#follows/1', $this->dig($reject, 0, 'object', 'id'));
        self::assertSame(FakeFediverse::ALICE, $this->dig($reject, 0, 'object', 'actor'));
        self::assertSame(FakeFediverse::ALICE.'/inbox', $this->dig($this->fediverse()->deliveries, \count($this->fediverse()->deliveries) - 1, 'url'));
        self::assertFalse(self::getContainer()->get(FollowRepository::class)->isFollowing($this->alice(), $this->reloadAccount($camille->getAccount())));
    }

    public function testARemoteFollowWaitsForTheApprovalOfTheAccount(): void
    {
        $camille = $this->login($this->register('camille'));
        $camille->getAccount()->requireFollowApproval(true);
        self::getContainer()->get('doctrine')->getManager()->flush();

        $this->client->request('GET', '/users/camille', server: ['HTTP_ACCEPT' => self::AP]);
        self::assertTrue($this->json()['manuallyApprovesFollowers'], 'Le fédivers sait que la demande sera validée.');

        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/7', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        self::assertSame([], $this->fediverse()->delivered('Accept'), 'Pas d’Accept avant la décision.');
        self::assertFalse(self::getContainer()->get(FollowRepository::class)->isFollowing($this->alice(), $this->reloadAccount($camille->getAccount())));

        // Acceptée depuis la liste des demandes : Accept du Follow d'origine.
        $crawler = $this->client->request('GET', '/abonnes/demandes');
        self::assertSelectorTextContains('.netlist', 'Alice Martin');
        $this->client->submit($crawler->selectButton('Accepter')->form());
        $accept = $this->fediverse()->delivered('Accept');
        self::assertCount(1, $accept);
        self::assertSame('http://localhost/users/camille', $accept[0]['actor']);
        self::assertSame(FakeFediverse::ALICE.'#follows/7', $this->dig($accept, 0, 'object', 'id'));
        self::assertSame(FakeFediverse::ALICE.'/inbox', $this->dig($this->fediverse()->deliveries, \count($this->fediverse()->deliveries) - 1, 'url'));
        self::assertTrue(self::getContainer()->get(FollowRepository::class)->isFollowing($this->alice(), $this->reloadAccount($camille->getAccount())));

        // Le même abonnement redemandé plus tard est accepté d'office.
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/8', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        self::assertSame(FakeFediverse::ALICE.'#follows/8', $this->dig($this->fediverse()->delivered('Accept'), 0, 'object', 'id'));
    }

    public function testARefusedRemoteRequestIsRejected(): void
    {
        $camille = $this->login($this->register('camille'));
        $camille->getAccount()->requireFollowApproval(true);
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/7', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);

        $crawler = $this->client->request('GET', '/abonnes/demandes');
        $this->client->submit($crawler->selectButton('Refuser')->form());
        $reject = $this->fediverse()->delivered('Reject');
        self::assertCount(1, $reject);
        self::assertSame(FakeFediverse::ALICE.'#follows/7', $this->dig($reject, 0, 'object', 'id'));
        self::assertNull(self::getContainer()->get(FollowRepository::class)->findBetween($this->alice(), $this->reloadAccount($camille->getAccount())));
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

        $noteId = 'http://localhost/p/'.$post->getPublicId();
        self::assertSame($noteId, self::dig($this->fediverse()->delivered('Delete'), 0, 'object', 'id'));

        $editor->edit($post, 'Après', Visibility::Public, null, null, $order);
        $create = $this->fediverse()->delivered('Create');
        self::assertCount(2, $create, 'Elle y revient quand elle redevient visible.');
        // Mastodon garde une trace des objets supprimés : la publication revient sous un nouvel identifiant.
        self::assertSame($noteId.'/v2', self::dig($create, 1, 'object', 'id'));

        $this->client->request('GET', '/p/'.$post->getPublicId().'/v2', server: ['HTTP_ACCEPT' => self::AP]);
        self::assertSame($noteId.'/v2', $this->json()['id']);
        $this->client->request('GET', '/p/'.$post->getPublicId(), server: ['HTTP_ACCEPT' => self::AP]);
        self::assertResponseStatusCodeSame(404, 'L’ancien identifiant reste supprimé.');
        $this->client->request('GET', '/p/'.$post->getPublicId().'/v2');
        self::assertResponseIsSuccessful('Un navigateur affiche la publication.');
    }

    public function testArchivingRemovesAPostFromTheFediverseUntilItIsUnarchived(): void
    {
        $camille = $this->register('camille');
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/3', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $post = $this->publish($this->reloadAccount($camille->getAccount()), 'À archiver', Visibility::Public);
        $noteId = 'http://localhost/p/'.$post->getPublicId();
        self::assertCount(1, $this->fediverse()->delivered('Create'));

        $archiver = self::getContainer()->get(\App\Core\Application\Post\PostArchiver::class);
        $archiver->archive($post);
        self::assertSame($noteId, self::dig($this->fediverse()->delivered('Delete'), 0, 'object', 'id'));

        $this->client->request('GET', '/users/camille/outbox?page=1', server: ['HTTP_ACCEPT' => self::AP]);
        self::assertCount(0, $this->list($this->json(), 'orderedItems'), 'La boîte d’envoi ne montre pas les archives.');
        $this->client->request('GET', '/p/'.$post->getPublicId().'/v2', server: ['HTTP_ACCEPT' => self::AP]);
        self::assertResponseStatusCodeSame(404);

        // Modifier ou commenter une publication archivée ne la renvoie pas dans le fédivers.
        $post = self::getContainer()->get(PostRepository::class)->findByPublicId($post->getPublicId());
        self::assertInstanceOf(Post::class, $post);
        $order = array_values(array_map(\App\Media\Application\PostEditor::token(...), $post->getMedia()->toArray()));
        self::getContainer()->get(\App\Media\Application\PostEditor::class)->edit($post, 'Modifiée', Visibility::Public, null, null, $order);
        self::getContainer()->get(\App\Core\Application\Social\SocialActions::class)->comment($post->getAccount(), $post, 'Note pour moi');
        // (les requêtes HTTP ci-dessus ont redémarré le noyau : les envois sont comptés à partir de là)
        self::assertCount(0, $this->fediverse()->delivered('Update'));
        self::assertCount(0, $this->fediverse()->delivered('Create'));

        $post = self::getContainer()->get(PostRepository::class)->findByPublicId($post->getPublicId());
        self::assertInstanceOf(Post::class, $post);
        self::getContainer()->get(\App\Core\Application\Post\PostArchiver::class)->unarchive($post);
        $create = $this->fediverse()->delivered('Create');
        self::assertCount(1, $create);
        self::assertSame($noteId.'/v2', self::dig($create, 0, 'object', 'id'), 'La publication revient sous un nouvel identifiant.');
    }

    public function testImportedPostsAreAnnouncedOnlyWhenAsked(): void
    {
        $archive = \App\Tests\Import\InstagramArchive::build(fn (): \Symfony\Component\HttpFoundation\File\UploadedFile => $this->jpeg(200, 250));
        try {
            // Import discret (par défaut) pour camille, import annoncé pour hugo : Alice suit les deux.
            foreach ([['camille', \App\Import\Instagram\Domain\Placement::PublicQuiet, 0], ['hugo', \App\Import\Instagram\Domain\Placement::Public, 3]] as [$username, $placement, $announced]) {
                $account = $this->register($username)->getAccount();
                $this->inbox('/users/'.$username.'/inbox', ['id' => FakeFediverse::ALICE.'#follows/'.$username, 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/'.$username]);

                $container = self::getContainer();
                $import = $container->get(\App\Import\Instagram\Application\ArchiveUploads::class)->fromFiles($this->reloadAccount($account), [$archive]);
                $reader = new \App\Import\Instagram\Infrastructure\ArchiveReader([$archive]);
                $container->get(\App\Import\Instagram\Application\ArchiveAnalyzer::class)->analyze($import, $reader);
                $reader->close();
                $container->get(\App\Import\Instagram\Application\ArchiveImporter::class)->launch($import, $placement, true, true);

                $creates = $this->fediverse()->delivered('Create');
                self::assertCount($announced, $creates, $placement->label());
                if ($announced > 0) {
                    self::assertSame('2019-06-14T12:00:00+00:00', self::dig($creates, $announced - 1, 'object', 'published'), 'Le fédivers reçoit la date d’origine.');
                }
            }

            // Plus tard, camille annonce finalement ses publications importées discrètement.
            $before = \count($this->fediverse()->delivered('Create'));
            $console = new Application(self::$kernel ?? self::bootKernel());
            $command = new CommandTester($console->find('app:import:announce'));
            $command->execute(['identifier' => 'camille', '--dry-run' => true]);
            self::assertStringContainsString('3 publications importées à annoncer', $command->getDisplay());
            self::assertCount($before, $this->fediverse()->delivered('Create'), 'Essai à blanc : rien n’est envoyé.');

            $command->execute(['identifier' => 'camille', '--pause' => '0']);
            $command->assertCommandIsSuccessful();
            $creates = \array_slice($this->fediverse()->delivered('Create'), $before);
            self::assertCount(3, $creates, 'Les trois publications importées partent vers Alice.');
            foreach (array_keys($creates) as $i) {
                self::assertSame('http://localhost/users/camille', $this->str($creates, $i, 'actor'));
            }
            $dates = array_map(fn (int $i): string => $this->str($creates, $i, 'object', 'published'), array_keys($creates));
            $sorted = $dates;
            sort($sorted);
            self::assertSame($sorted, $dates, 'De la plus ancienne à la plus récente.');
            self::assertSame(['2016-03-01T12:00:00+00:00', '2019-06-14T12:00:00+00:00'], [$dates[0], $dates[2]], 'À leur date d’origine.');

            $command->execute(['identifier' => 'camille']);
            self::assertStringContainsString('Aucune publication importée à annoncer', $command->getDisplay());
            self::assertCount($before + 3, $this->fediverse()->delivered('Create'), 'Une publication n’est annoncée qu’une fois.');
        } finally {
            @unlink($archive);
        }
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
        // Image copiée ici (CacheRemoteMedia) : servie depuis /m/, en plusieurs tailles, au lieu de l'original distant.
        $img = $this->client->getCrawler()->filter('article.post .media img[alt="Ciel étoilé"]');
        self::assertCount(1, $img);
        self::assertStringStartsWith('/m/', (string) $img->attr('src'));
        self::assertStringContainsString('-large_640.webp 640w', (string) $img->attr('srcset'));
        $copies = $this->remoteMediaOf('https://remote.test/notes/1')->getLocalDerivatives();
        self::assertNotEmpty($copies);
        self::assertSelectorTextContains('article.post .fed', 'remote.test');
        $this->client->request('GET', '/?fil=federe');
        self::assertSelectorCount(1, 'article.post', 'Onglet Fédéré : les comptes suivis du fédivers.');
        $this->client->request('GET', '/?fil=instance');
        self::assertSelectorNotExists('article.post', 'Onglet Instance : pas de compte distant.');

        // Like et commentaire envoyés à l'autrice
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('form[action$="/aimer"]')->form());
        $like = $this->fediverse()->delivered('Like');
        self::assertSame('https://remote.test/notes/1', $like[0]['object']);

        $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submitForm('Publier', ['content' => 'Magnifique !']);
        $reply = $this->fediverse()->delivered('Create');
        self::assertSame('https://remote.test/notes/1', $this->dig($reply, 0, 'object', 'inReplyTo'));

        // Suppression par l'autrice
        $this->inbox('/inbox', ['id' => 'https://remote.test/notes/1#delete', 'type' => 'Delete', 'actor' => FakeFediverse::ALICE, 'object' => ['id' => 'https://remote.test/notes/1', 'type' => 'Tombstone']]);
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNull(self::getContainer()->get(PostRepository::class)->findOneBy(['apUri' => 'https://remote.test/notes/1']));
        foreach ($copies as $path) {
            self::assertFalse($this->derivatives()->fileExists($path), 'Copies locales supprimées avec la publication : '.$path);
        }
    }

    public function testARemoteImageThatCannotBeDownloadedKeepsItsOriginalAddress(): void
    {
        $this->followAlice();
        $note = $this->remoteNote('https://remote.test/notes/2', 'Brume', imageUrl: 'https://remote.test/media/absente.jpg');
        $this->inbox('/inbox', ['id' => 'https://remote.test/notes/2/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => $note]);
        self::assertResponseStatusCodeSame(202);

        self::assertFalse($this->remoteMediaOf('https://remote.test/notes/2')->hasLocalCopies());
        $this->client->request('GET', '/');
        self::assertSelectorExists('article.post img[src="https://remote.test/media/absente.jpg"]:not([srcset])');
    }

    public function testTheCopiesOfARemoteAccountGoAwayWithIt(): void
    {
        $this->followAlice();
        $this->inbox('/inbox', ['id' => 'https://remote.test/notes/3/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => $this->remoteNote('https://remote.test/notes/3', 'Lac')]);
        $copies = $this->remoteMediaOf('https://remote.test/notes/3')->getLocalDerivatives();
        self::assertNotEmpty($copies);

        $this->inbox('/inbox', ['id' => FakeFediverse::ALICE.'#delete', 'type' => 'Delete', 'actor' => FakeFediverse::ALICE, 'object' => FakeFediverse::ALICE]);
        self::assertResponseStatusCodeSame(202);
        foreach ($copies as $path) {
            self::assertFalse($this->derivatives()->fileExists($path), $path);
        }
    }

    /**
     * Médias reçus avant la copie locale : la commande les copie (image entière ; affiche seule pour une vidéo).
     */
    public function testTheCommandCopiesTheRemoteMediaReceivedBefore(): void
    {
        $this->followAlice();
        $em = self::getContainer()->get('doctrine')->getManager();
        $post = Post::federated($this->alice(), 'Avant', Visibility::Public, null, 'https://remote.test/notes/4', 'https://remote.test/notes/4', new \DateTimeImmutable('-1 day'));
        $image = MediaAttachment::remote($post, MediaType::Image, 0, 'https://remote.test/media/4.jpg', 'image/jpeg', null, 1080, 1350, null);
        $video = MediaAttachment::remote($post, MediaType::Video, 1, 'https://remote.test/media/5.mp4', 'video/mp4', null, 1080, 1920, null, 'https://remote.test/media/5.jpg');
        $em->persist($post);
        $em->flush();

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:media:cache-remote'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 copies demandées', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');

        $em->clear();
        $image = $em->find(MediaAttachment::class, $image->getId());
        $video = $em->find(MediaAttachment::class, $video->getId());
        self::assertInstanceOf(MediaAttachment::class, $image);
        self::assertInstanceOf(MediaAttachment::class, $video);
        self::assertTrue($image->hasLocalCopies());
        self::assertNotNull($image->getDerivative('large_960'));
        $urls = self::getContainer()->get(MediaUrls::class);
        self::assertStringStartsWith('/m/', (string) $urls->derivative($video, MediaAttachment::DERIVATIVE_POSTER));
        self::assertStringStartsWith('/m/', (string) $urls->derivative($video, MediaAttachment::DERIVATIVE_THUMB));
        self::assertSame('https://remote.test/media/5.mp4', $urls->derivative($video, MediaAttachment::DERIVATIVE_VIDEO), 'La vidéo reste sur son instance.');
        self::assertSame(1920, $video->getHeight(), 'Dimensions de la vidéo, pas de son affiche.');

        // Relancée : plus rien à demander.
        $tester->execute([]);
        self::assertStringContainsString('0 copie demandée', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    private function remoteMediaOf(string $apUri): MediaAttachment
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $post = self::getContainer()->get(PostRepository::class)->findOneBy(['apUri' => $apUri]);
        self::assertInstanceOf(Post::class, $post);
        $media = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $media);

        return $media;
    }

    private function derivatives(): FilesystemOperator
    {
        return self::getContainer()->get('derivatives.storage');
    }

    public function testCommentsOnLocalPostsAreFederatedAndTheirDeletionToo(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#follows/9', 'type' => 'Follow', 'actor' => FakeFediverse::ALICE, 'object' => 'http://localhost/users/camille']);
        $post = $this->publish($this->reloadAccount($camille), 'Coucher de soleil', Visibility::Public);
        $hugo = $this->register('hugo')->getAccount();
        $social = self::getContainer()->get(\App\Core\Application\Social\SocialActions::class);

        $comment = $social->comment($hugo, $post, 'Quelles couleurs !');
        $create = array_values(array_filter($this->fediverse()->delivered('Create'), static fn (array $a): bool => \is_array($a['object'] ?? null) && 'http://localhost/c/'.$comment->getPublicId() === ($a['object']['id'] ?? null)));
        self::assertCount(1, $create, 'Les abonnés distants de l’autrice voient la réponse.');
        self::assertSame('http://localhost/p/'.$post->getPublicId(), self::dig($create, 0, 'object', 'inReplyTo'));
        self::assertSame(['https://www.w3.org/ns/activitystreams#Public'], self::dig($create, 0, 'object', 'to'));

        $social->deleteComment($comment);
        $delete = $this->fediverse()->delivered('Delete');
        self::assertSame('http://localhost/c/'.$comment->getPublicId(), self::dig($delete, 0, 'object', 'id'));

        $private = $this->publish($this->reloadAccount($camille), 'Pour moi', Visibility::Private);
        $private = self::getContainer()->get(PostRepository::class)->find($private->getId());
        self::assertInstanceOf(Post::class, $private);
        $social->comment($private->getAccount(), $private, 'Note perso');
        self::assertCount(2, $this->fediverse()->delivered('Create'), 'Rien ne sort pour une publication privée (seule la publication publique et le commentaire sont partis).');
    }

    /**
     * Publications texte du fédivers : une Note sans image ni vidéo est gardée, une Note vide sans média ignorée.
     */
    public function testATextNoteFromAFollowedAccountIsKeptAndAnEmptyOneIgnored(): void
    {
        $this->followAlice();
        $create = fn (string $id, string $content): array => ['id' => $id.'/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => $this->remoteNote($id, $content, withImage: false)];

        $this->inbox('/users/camille/inbox', $create('https://remote.test/notes/t1', '<p>Bonjour le fédivers</p>'));
        self::assertResponseStatusCodeSame(202);
        $this->inbox('/users/camille/inbox', $create('https://remote.test/notes/t2', '<p></p>'));

        $posts = self::getContainer()->get(PostRepository::class);
        $text = $posts->findOneBy(['apUri' => 'https://remote.test/notes/t1']);
        self::assertNotNull($text);
        self::assertSame('Bonjour le fédivers', $text->getCaption());
        self::assertCount(0, $text->getMedia());
        self::assertNull($posts->findOneBy(['apUri' => 'https://remote.test/notes/t2']), 'Rien à afficher : ignorée.');
    }

    /**
     * Profil d'un compte du fédivers ouvert ici, même sans abonnement : son historique récent est récupéré et ses
     * nombres d'abonnés, d'abonnements et de publications sont ceux de son instance ; au plus une fois par jour.
     */
    public function testOpeningARemoteProfileReadsItsHistoryAndCountsOncePerDay(): void
    {
        FakeFediverse::$outbox = [[
            'id' => 'https://remote.test/notes/h1/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE,
            'object' => ['published' => new \DateTimeImmutable('-1 day')->format(\DATE_ATOM)] + $this->remoteNote('https://remote.test/notes/h1', '<p>Un texte récent</p>', withImage: false),
        ]];
        $this->login($this->register('camille'));

        $this->client->request('GET', '/@alice@remote.test');
        self::assertResponseIsSuccessful();
        self::assertNotNull(self::getContainer()->get(PostRepository::class)->findOneBy(['apUri' => 'https://remote.test/notes/h1']), 'Historique lu sans abonnement.');
        self::assertSelectorTextContains('.stats', '1 publication');
        self::assertSelectorTextContains('.stats', '12 abonnés');
        self::assertSelectorTextContains('.stats', '26 abonnements');
        self::assertSelectorTextContains('.stats .stats-src', 'chiffres de remote.test');
        $reads = \count(array_filter(FakeFediverse::$reads, static fn (string $path): bool => '/users/alice/outbox' === $path));
        self::assertGreaterThan(0, $reads);

        // Rouvert le même jour : rien n'est relu.
        $this->client->request('GET', '/@alice@remote.test');
        self::assertCount($reads, array_filter(FakeFediverse::$reads, static fn (string $path): bool => '/users/alice/outbox' === $path));
    }

    public function testFollowingARemoteAccountImportsItsRecentHistory(): void
    {
        $create = fn (string $id, string $published, ?string $inReplyTo = null, bool $withImage = true): array => [
            'id' => $id.'/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE,
            // Petite photo : son traitement est refait pour chacune des 30 publications importées.
            'object' => ['published' => $published] + $this->remoteNote($id, 'Photo '.basename($id), $inReplyTo, $withImage, 'https://remote.test/media/petite/1.jpg'),
        ];
        $day = static fn (int $days): string => new \DateTimeImmutable(\sprintf('-%d days', $days))->format(\DATE_ATOM);
        FakeFediverse::$outbox = [
            $create('https://remote.test/notes/recent', $day(1)),
            ['id' => 'https://remote.test/boosts/1', 'type' => 'Announce', 'actor' => FakeFediverse::ALICE, 'object' => 'https://ailleurs.test/notes/9'],
            $create('https://remote.test/notes/reply', $day(2), 'https://ailleurs.test/notes/9'),
            $create('https://remote.test/notes/text', $day(3), withImage: false),
        ];
        // Deux pages : 30 publications récentes de plus (la limite de 50 n'est pas atteinte), puis une trop ancienne.
        for ($i = 1; $i <= 30; ++$i) {
            FakeFediverse::$outbox[] = $create('https://remote.test/notes/p'.$i, $day(10 + $i));
        }
        FakeFediverse::$outbox[] = $create('https://remote.test/notes/old', $day(200));
        FakeFediverse::$outbox[] = $create('https://remote.test/notes/older', $day(210));

        $this->followAlice();

        $posts = self::getContainer()->get(PostRepository::class);
        self::assertNotNull($posts->findOneBy(['apUri' => 'https://remote.test/notes/recent']));
        self::assertNotNull($posts->findOneBy(['apUri' => 'https://remote.test/notes/p30']), 'Les pages suivantes de l’outbox sont lues.');
        self::assertNull($posts->findOneBy(['apUri' => 'https://remote.test/notes/old']), 'Rien au-delà de six mois.');
        self::assertSame(32, $posts->count([]), 'Ni partages, ni réponses ; les messages sans photo sont gardés (publications texte).');
        $text = $posts->findOneBy(['apUri' => 'https://remote.test/notes/text']);
        self::assertNotNull($text);
        self::assertCount(0, $text->getMedia(), 'Publication texte, sans média.');

        $this->client->request('GET', '/@alice@remote.test');
        self::assertSelectorExists('a.gt[href*="/publications?depuis="]');
    }

    public function testHistoryIsLimitedToTheLatestFiftyPosts(): void
    {
        for ($i = 1; $i <= 70; ++$i) {
            FakeFediverse::$outbox[] = ['id' => 'https://remote.test/notes/'.$i.'/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE,
                'object' => ['published' => new \DateTimeImmutable('-'.$i.' hours')->format(\DATE_ATOM)] + $this->remoteNote('https://remote.test/notes/'.$i, 'Photo '.$i, imageUrl: 'https://remote.test/media/petite/'.$i.'.jpg')];
        }

        $this->followAlice();

        $posts = self::getContainer()->get(PostRepository::class);
        self::assertSame(50, $posts->count([]));
        self::assertNotNull($posts->findOneBy(['apUri' => 'https://remote.test/notes/50']));
        self::assertNull($posts->findOneBy(['apUri' => 'https://remote.test/notes/51']));
    }

    public function testRemoteLikesAndRepliesOnALocalPost(): void
    {
        $camille = $this->register('camille')->getAccount();
        $post = $this->publish($camille, 'Étretat', Visibility::Public);
        $url = 'http://localhost/p/'.$post->getPublicId();

        $this->inbox('/inbox', ['id' => FakeFediverse::ALICE.'#likes/1', 'type' => 'Like', 'actor' => FakeFediverse::ALICE, 'object' => $url]);
        self::assertSame(1, $this->reloadPost($post)->getLikesCount());
        self::assertSame([\App\Core\Domain\Notification\NotificationType::Like], $this->notificationTypes(), 'Camille est notifiée du J’aime venu du fédivers.');

        $this->inbox('/inbox', ['id' => FakeFediverse::ALICE.'#undo/1', 'type' => 'Undo', 'actor' => FakeFediverse::ALICE, 'object' => ['id' => FakeFediverse::ALICE.'#likes/1', 'type' => 'Like', 'actor' => FakeFediverse::ALICE, 'object' => $url]]);
        self::assertSame(0, $this->reloadPost($post)->getLikesCount());
        self::assertSame([], $this->notificationTypes(), 'J’aime retiré : notification retirée.');

        $reply = $this->remoteNote('https://remote.test/notes/2', '<p><span class="h-card"><a href="http://localhost/@camille">@camille</a></span> Superbe lumière</p>', $url, withImage: false);
        $this->inbox('/inbox', ['id' => 'https://remote.test/notes/2/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => $reply]);
        self::assertSame(1, $this->reloadPost($post)->getCommentsCount());
        self::assertSame([\App\Core\Domain\Notification\NotificationType::Comment], $this->notificationTypes());

        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorTextContains('.cmts', 'Superbe lumière');
        self::assertSelectorTextContains('.cmts', '@remote.test');
    }

    /**
     * @return list<\App\Core\Domain\Notification\NotificationType>
     */
    private function notificationTypes(): array
    {
        self::getContainer()->get('doctrine')->getManager()->clear();

        return array_map(static fn (\App\Core\Domain\Notification\Notification $n): \App\Core\Domain\Notification\NotificationType => $n->getType(), self::getContainer()->get(\App\Core\Infrastructure\Doctrine\NotificationRepository::class)->findAll());
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function inbox(string $path, array $activity): void
    {
        $body = (string) json_encode($activity, \JSON_UNESCAPED_SLASHES);
        $this->client->request('POST', $path, server: FakeFediverse::signedServer('http://localhost'.$path, $body), content: $body);
    }

    protected function tearDown(): void
    {
        FakeFediverse::$outbox = [];
        FakeFediverse::$reads = [];
        parent::tearDown();
    }

    private function followAlice(): void
    {
        $this->login($this->register('camille'));
        $this->client->request('GET', '/recherche?q=@alice@remote.test');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Suivre')->form());
        $follow = $this->fediverse()->delivered('Follow');
        $this->inbox('/users/camille/inbox', ['id' => FakeFediverse::ALICE.'#accepts/1', 'type' => 'Accept', 'actor' => FakeFediverse::ALICE, 'object' => $follow[0]]);
        self::assertResponseStatusCodeSame(202);
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteNote(string $id, string $content, ?string $inReplyTo = null, bool $withImage = true, string $imageUrl = 'https://remote.test/media/1.jpg'): array
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
            'attachment' => $withImage ? [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => $imageUrl, 'name' => 'Ciel étoilé', 'width' => 1080, 'height' => 1350]] : [],
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
