<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Post\Visibility;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Fil d'accueil : ses publications et celles des comptes suivis, jamais celles des autres, page après page.
 * La requête lit d'abord la liste des auteurs (index account_id, published_at) au lieu de parcourir toute la table.
 */
final class HomeTimelineTest extends AppWebTestCase
{
    public function testTheHomeFeedShowsOwnAndFollowedPostsOnlyAcrossPages(): void
    {
        $camille = $this->login($this->register('camille'));
        $leo = $this->register('leo');
        $zoe = $this->register('zoe');
        self::getContainer()->get(SocialActions::class)->follow($camille->getAccount(), $leo->getAccount());
        $composer = self::getContainer()->get(PostComposer::class);

        $composer->publish($camille->getAccount(), 'Les miennes, même réservées aux abonnés', [['file' => $this->jpeg(), 'alt' => null]], Visibility::Followers);
        for ($i = 1; $i <= 10; ++$i) {
            $composer->publish($leo->getAccount(), 'Leo '.$i, [['file' => $this->jpeg(), 'alt' => null]]);
        }
        $composer->publish($zoe->getAccount(), 'Zoé, non suivie', [['file' => $this->jpeg(), 'alt' => null]]);

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(10, 'article.post', 'Première page : dix publications.');
        self::assertSelectorTextNotContains('main', 'Zoé, non suivie');

        // Page suivante, chargée au défilement en flux Turbo : la onzième publication, la plus ancienne (la sienne).
        $next = $crawler->filter('#feed-more a[href*="apres="]')->attr('href');
        self::assertNotNull($next);
        $this->client->request('GET', $next, server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertSelectorCount(1, 'article.post');
        self::assertSelectorTextContains('article.post', 'Les miennes, même réservées aux abonnés');
    }
}
