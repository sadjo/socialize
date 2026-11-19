<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Notification\Notification;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\NotificationRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Notifications : J'aime et commentaires reçus, lus ou non lus, supprimés un à un ou tous ensemble.
 */
final class NotificationTest extends AppWebTestCase
{
    public function testLikesAndCommentsAreNotifiedToTheAuthor(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Lac', [['file' => $this->jpeg(), 'alt' => null]]);
        $social = self::getContainer()->get(SocialActions::class);

        // Ses propres actions ne la notifient pas.
        $social->toggleLike($camille->getAccount(), $post);
        $social->comment($camille->getAccount(), $post, 'Mon lac');
        self::assertSame([], $this->all());

        $social->toggleLike($this->account($hugo->getAccount()), $this->post($post));
        $social->comment($this->account($hugo->getAccount()), $this->post($post), 'Superbe lumière');
        self::assertCount(2, $this->all());

        // Pastille dans la barre du bas (à la place des réglages) et dans le menu.
        $this->login($camille);
        $this->client->request('GET', '/');
        self::assertSelectorTextSame('nav.tabbar a.notif-link[href="/notifications"] .badge', '2');
        self::assertSelectorNotExists('nav.tabbar a[href="/reglages"]', 'Réglages : dans le menu.');
        self::assertSelectorExists('nav.side a[href="/reglages"]');
        self::assertSelectorTextSame('nav.side a.notif-link .badge', '2');

        $crawler = $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.notif.unread');
        self::assertSelectorTextContains('.notifs', 'Hugo a commenté votre publication : « Superbe lumière »');
        self::assertSelectorTextContains('.notifs', 'Hugo a aimé votre publication.');

        // Ouvrir : vers la publication, au commentaire ; la notification est lue.
        $this->client->submit($crawler->filter('.notif')->first()->filter('.notif-open button')->form());
        $comment = $this->all()[0]->getComment();
        self::assertNotNull($comment);
        self::assertResponseRedirects('/p/'.$post->getPublicId().'#comment-'.$comment->getPublicId());
        $crawler = $this->client->request('GET', '/notifications');
        self::assertSelectorCount(1, '.notif.unread');

        // Icônes : un œil pour marquer comme lue, un œil barré pour revenir à non lue.
        self::assertSelectorExists('.notif.unread button[aria-label="Marquer comme lue"] use[href="#i-eye"]');
        self::assertSelectorExists('.notif:not(.unread) button[aria-label="Marquer comme non lue"] use[href="#i-eye-off"]');

        // Lue → non lue, puis tout marquer comme lu.
        $this->client->submit($crawler->filter('.notif')->first()->filter('button[aria-label="Marquer comme non lue"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorCount(2, '.notif.unread');
        $this->client->submit($crawler->selectButton('Tout marquer comme lu')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorCount(0, '.notif.unread');
        self::assertSelectorNotExists('nav.tabbar .notif-link .badge');

        // J'aime retiré : sa notification aussi (services repris : le noyau a redémarré).
        $social = self::getContainer()->get(SocialActions::class);
        $social->toggleLike($this->account($hugo->getAccount()), $this->post($post));
        self::assertCount(1, $this->all());

        // Supprimer une notification, puis toutes.
        $social->toggleLike($this->account($hugo->getAccount()), $this->post($post));
        $crawler = $this->client->request('GET', '/notifications');
        $this->client->submit($crawler->filter('.notif')->first()->filter('button[aria-label="Supprimer la notification"]')->form());
        self::assertCount(1, $this->all());
        $crawler = $this->client->request('GET', '/notifications');
        $this->client->submit($crawler->selectButton('Tout supprimer')->form());
        self::assertSame([], $this->all());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.empty-notifs', 'Aucune notification');
    }

    public function testNotificationsArePrivate(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $composer = self::getContainer()->get(PostComposer::class);
        $social = self::getContainer()->get(SocialActions::class);
        $hersPost = $composer->publish($camille->getAccount(), 'Lac', [['file' => $this->jpeg(), 'alt' => null]]);
        $social->toggleLike($hugo->getAccount(), $hersPost);
        $hers = $this->all()[0];
        $hisPost = $composer->publish($this->account($hugo->getAccount()), 'Mer', [['file' => $this->jpeg(), 'alt' => null]]);
        $social->toggleLike($this->account($camille->getAccount()), $hisPost);

        // Hugo ne voit que la sienne, et ne peut pas toucher à celle de Camille, même avec un jeton valable.
        $this->login($hugo);
        $crawler = $this->client->request('GET', '/notifications');
        self::assertSelectorCount(1, '.notif');
        self::assertSelectorTextContains('.notifs', 'Camille a aimé');
        $token = $crawler->filter('.notif input[name="_token"]')->first()->attr('value');
        foreach (['supprimer', 'lue', 'ouvrir'] as $action) {
            $this->client->request('POST', '/notifications/'.$hers->getId()->toRfc4122().'/'.$action, ['_token' => $token]);
            self::assertResponseStatusCodeSame(404, $action);
        }
        self::assertCount(2, $this->all());
        self::assertFalse($this->all()[1]->isRead());

        $this->client->request('GET', '/deconnexion');
        $this->client->request('GET', '/notifications');
        self::assertResponseRedirects();
    }

    /**
     * Notifications relues en base à chaque appel.
     *
     * @return list<Notification>
     *
     * @phpstan-impure
     */
    private function all(): array
    {
        self::getContainer()->get('doctrine')->getManager()->clear();

        return self::getContainer()->get(NotificationRepository::class)->findBy([], ['createdAt' => 'DESC', 'type' => 'ASC']);
    }

    private function post(Post $post): Post
    {
        $fresh = self::getContainer()->get(PostRepository::class)->find($post->getId());
        self::assertInstanceOf(Post::class, $fresh);

        return $fresh;
    }

    private function account(Account $account): Account
    {
        $fresh = self::getContainer()->get('doctrine')->getRepository(Account::class)->find($account->getId());
        self::assertInstanceOf(Account::class, $fresh);

        return $fresh;
    }
}
