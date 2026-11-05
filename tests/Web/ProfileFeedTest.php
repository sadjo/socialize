<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ProfileFeedTest extends AppWebTestCase
{
    public function testAGridThumbnailOpensTheProfileFeedOnThatPost(): void
    {
        $camille = $this->register('camille')->getAccount();
        $posts = [];
        for ($i = 1; $i <= 5; ++$i) {
            $posts[$i] = $this->publish($camille, 'Photo '.$i);
        }
        $this->publish($camille, 'Secrète', Visibility::Private);

        $crawler = $this->client->request('GET', '/@camille');
        $link = $crawler->filter('a.gt')->eq(2)->attr('href'); // grille : 5, 4, 3…
        self::assertSame('/@camille/publications?depuis='.$posts[3]->getPublicId().'#post-'.$posts[3]->getPublicId(), $link);

        $crawler = $this->client->request('GET', strtok($link, '#'));
        self::assertResponseIsSuccessful();
        // Plus récentes au-dessus, puis la publication ouverte, puis les plus anciennes.
        self::assertSame(
            array_map(static fn (Post $p): string => 'post-'.$p->getPublicId(), [$posts[5], $posts[4], $posts[3], $posts[2], $posts[1]]),
            $crawler->filter('article.post')->each(static fn ($n): string => (string) $n->attr('id')),
        );
        self::assertSelectorNotExists('article.post:contains("Secrète")');

        // Une publication d'un autre compte ramène au profil.
        $other = $this->publish($this->register('dominique')->getAccount(), 'Ailleurs');
        $this->client->request('GET', '/@camille/publications?depuis='.$other->getPublicId());
        self::assertResponseRedirects('/@camille');
    }

    public function testOlderPostsAreLoadedPageByPage(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->publish($camille, 'La toute première');
        for ($i = 1; $i <= 12; ++$i) {
            $this->publish($camille, 'Photo '.$i);
        }
        $last = $this->publish($camille, 'La plus récente');

        $crawler = $this->client->request('GET', '/@camille/publications?depuis='.$last->getPublicId());
        self::assertCount(11, $crawler->filter('article.post'), 'La publication ouverte et 10 plus anciennes.');
        $next = $crawler->filter('turbo-frame[loading="lazy"]')->attr('src');
        self::assertStringStartsWith('/@camille/publications?apres=', (string) $next);

        $this->client->request('GET', (string) $next);
        self::assertSelectorTextContains('article.post:last-of-type', 'La toute première');
        self::assertSelectorCount(3, 'article.post');
    }

    public function testRemoteVideosShowTheirFirstFrameOrTheirPreview(): void
    {
        $alice = Account::remote('alice', 'remote.test', 'https://remote.test/users/alice');
        $post = Post::federated($alice, 'Timelapse', Visibility::Public, null, 'https://remote.test/notes/1', 'https://remote.test/@alice/1', new \DateTimeImmutable());
        MediaAttachment::remote($post, MediaType::Video, 0, 'https://remote.test/media/1.mp4', 'video/mp4', null, 1080, 1920, null);
        $previewed = Post::federated($alice, 'Avec vignette', Visibility::Public, null, 'https://remote.test/notes/2', 'https://remote.test/@alice/2', new \DateTimeImmutable());
        MediaAttachment::remote($previewed, MediaType::Video, 0, 'https://remote.test/media/2.mp4', 'video/mp4', null, 1080, 1920, null, 'https://remote.test/media/2.jpg');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($alice);
        $em->persist($post);
        $em->persist($previewed);
        $em->flush();

        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorExists('video[preload="metadata"]:not([poster]) source[src="https://remote.test/media/1.mp4#t=0.1"]');
        $this->client->request('GET', '/p/'.$previewed->getPublicId());
        self::assertSelectorExists('video[preload="none"][poster="https://remote.test/media/2.jpg"]');

        $this->client->request('GET', '/@alice@remote.test');
        self::assertSelectorExists('a.gt video[src="https://remote.test/media/1.mp4#t=0.1"]');
        self::assertSelectorExists('a.gt img[src="https://remote.test/media/2.jpg"]');
    }

    private function publish(Account $author, string $caption, Visibility $visibility = Visibility::Public): Post
    {
        return self::getContainer()->get(PostComposer::class)->publish($author, $caption, [['file' => $this->jpeg(200, 250), 'alt' => null]], $visibility);
    }
}
