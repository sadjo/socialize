<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

final class SocialTest extends AppWebTestCase
{
    public function testVisibilityRulesForAnonymousVisitorsFollowersAndAuthor(): void
    {
        $camille = $this->register('camille');
        $public = $this->publish($camille->getAccount(), 'Publique', Visibility::Public);
        $followers = $this->publish($camille->getAccount(), 'Pour mes abonnés', Visibility::Followers);
        $private = $this->publish($camille->getAccount(), 'Juste pour moi', Visibility::Private);
        $unlisted = $this->publish($camille->getAccount(), 'Avec le lien', Visibility::Unlisted);

        // Visiteur anonyme
        $this->client->request('GET', '/p/'.$public->getPublicId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/p/'.$unlisted->getPublicId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/p/'.$followers->getPublicId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/local');
        self::assertSelectorCount(1, 'article.post', 'Le fil local ne montre que les publications publiques.');

        // Abonné
        $this->login($this->register('hugo'));
        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Suivre')->form());
        self::assertResponseRedirects();
        $this->client->request('GET', '/p/'.$followers->getPublicId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/p/'.$private->getPublicId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/');
        self::assertSelectorCount(3, 'article.post', 'Le fil Abonnements contient les publications publiques, non répertoriées et réservées aux abonnés.');

        // Autrice
        $this->login($camille);
        $this->client->request('GET', '/p/'.$private->getPublicId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/@camille');
        self::assertSelectorTextContains('.stats', '4 publications');
        self::assertSelectorTextContains('.stats', '1 abonné');
    }

    public function testLikeWithTurboStreamAndDoubleTapNeverUnlikes(): void
    {
        $camille = $this->register('camille');
        $post = $this->publish($camille->getAccount(), 'À aimer', Visibility::Public);
        $this->login($this->register('hugo'));

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $token = $crawler->filter('form[action$="/aimer"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/p/'.$post->getPublicId().'/aimer', ['_token' => $token], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<turbo-stream action="replace"', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('1 J’aime', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/p/'.$post->getPublicId().'/aimer', ['_token' => $token, 'source' => 'double-tap'], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertStringContainsString('1 J’aime', (string) $this->client->getResponse()->getContent(), 'Un double tap n’annule jamais un like.');

        $this->client->request('POST', '/p/'.$post->getPublicId().'/aimer', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertSame(0, $this->reload($post)->getLikesCount());
    }

    public function testComment(): void
    {
        $camille = $this->register('camille');
        $post = $this->publish($camille->getAccount(), 'À commenter', Visibility::Public);
        $this->login($this->register('hugo'));

        $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submitForm('Publier', ['comment[content]' => 'Superbe lumière !']);
        self::assertResponseRedirects('/p/'.$post->getPublicId().'#commentaires');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.cmts', 'Superbe lumière !');
        self::assertSame(1, $this->reload($post)->getCommentsCount());
    }

    public function testDeletingAPostAlsoDeletesItsLikesAndComments(): void
    {
        $camille = $this->register('camille');
        $post = $this->publish($camille->getAccount(), 'À supprimer', Visibility::Public);
        $hugo = $this->register('hugo')->getAccount();
        $social = self::getContainer()->get(\App\Core\Application\Social\SocialActions::class);
        $social->like($hugo, $post);
        $social->comment($hugo, $post, 'Bientôt supprimé');

        self::getContainer()->get(PostComposer::class)->delete($post);

        // Clés étrangères ON DELETE CASCADE, y compris avec SQLite.
        self::assertSame(0, self::getContainer()->get(\App\Core\Infrastructure\Doctrine\LikeRepository::class)->count([]));
        self::assertSame(0, self::getContainer()->get(\App\Core\Infrastructure\Doctrine\CommentRepository::class)->count([]));
    }

    public function testFeedPaginationUsesACursor(): void
    {
        $camille = $this->register('camille');
        for ($i = 1; $i <= 12; ++$i) {
            $this->publish($camille->getAccount(), 'Photo '.$i, Visibility::Public);
        }

        $crawler = $this->client->request('GET', '/local');
        self::assertSelectorCount(10, 'article.post');
        $next = $crawler->filter('turbo-frame[loading="lazy"]')->attr('src');
        self::assertNotNull($next);

        $this->client->request('GET', $next);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'article.post');
        self::assertSelectorNotExists('turbo-frame[loading="lazy"]');
    }

    private function publish(\App\Core\Domain\Account\Account $author, string $caption, Visibility $visibility): Post
    {
        /** @var PostComposer $composer */
        $composer = self::getContainer()->get(PostComposer::class);

        return $composer->publish($author, $caption, [['file' => $this->jpeg(200, 250), 'alt' => null]], $visibility);
    }

    private function reload(Post $post): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(PostRepository::class)->findByPublicId($post->getPublicId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
