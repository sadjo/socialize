<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Post\Visibility;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Compteur de « J'aime » cliquable : il montre les personnes qui ont aimé la publication.
 */
final class PostLikersTest extends AppWebTestCase
{
    public function testTheLikesCountShowsWhoLikedThePost(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $lina = $this->register('lina');
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Lac', [['file' => $this->jpeg(), 'alt' => null]]);
        $social = self::getContainer()->get(SocialActions::class);
        $social->toggleLike($hugo->getAccount(), $post);
        $social->toggleLike($lina->getAccount(), $post);

        $this->login($camille);
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $id = $post->getPublicId();
        self::assertSelectorTextContains('button.likes-count[popovertarget="likers-'.$id.'"]', '2 J’aime');
        self::assertSame('/p/'.$id.'/jaime', $crawler->filter('#likers-'.$id.'[popover] turbo-frame[loading="lazy"]')->attr('src'));

        $crawler = $this->client->request('GET', '/p/'.$id.'/jaime');
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(['/@lina', '/@hugo'], $crawler->filter('turbo-frame#likers-frame-'.$id.' .likers-list a')->each(static fn ($a): ?string => $a->attr('href')), 'Les deux personnes.');
        self::assertCount(2, $crawler->filter('.likers-list a > .av'), 'Avatar par défaut, hors du bloc du nom.');
        self::assertCount(2, $crawler->filter('.likers-list a > .who'));
    }

    public function testNoListWithoutLikesAndNoListOfAPostOneCannotSee(): void
    {
        $camille = $this->register('camille');
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Privé', [['file' => $this->jpeg(), 'alt' => null]], Visibility::Private);

        $this->login($camille);
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorNotExists('button.likes-count');
        self::assertSelectorTextContains('.num', '0 J’aime');

        $this->login($this->register('hugo'));
        $this->client->request('GET', '/p/'.$post->getPublicId().'/jaime');
        self::assertResponseStatusCodeSame(404);
    }
}
