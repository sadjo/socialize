<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\SocialActions;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Aperçu des commentaires dans le fil : les deux derniers de chaque publication, du plus ancien au plus récent, lus en
 * une seule requête pour toute la page.
 */
final class CommentPreviewTest extends AppWebTestCase
{
    public function testTheFeedShowsTheLastTwoCommentsOfEachPostWithASingleQuery(): void
    {
        $camille = $this->login($this->register('camille'));
        $leo = $this->register('leo');
        $social = self::getContainer()->get(SocialActions::class);
        $composer = self::getContainer()->get(PostComposer::class);
        $posts = [];
        for ($i = 1; $i <= 3; ++$i) {
            $posts[$i] = $post = $composer->publish($camille->getAccount(), 'Photo '.$i, [['file' => $this->jpeg(), 'alt' => null]]);
            // Commentaires écrits dans la même seconde : l'ordre tient à leur identifiant.
            foreach (['un', 'deux', 'trois'] as $word) {
                $social->comment($leo->getAccount(), $post, $word.' '.$i);
            }
        }
        $quiet = $composer->publish($camille->getAccount(), 'Sans commentaire', [['file' => $this->jpeg(), 'alt' => null]]);

        // Seconde requête : noyau neuf, le profileur ne compte que celle-ci.
        $this->client->request('GET', '/');
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        foreach ($posts as $i => $post) {
            $preview = $crawler->filter('#post-'.$post->getPublicId().'-comments .cmt');
            self::assertCount(2, $preview, 'Photo '.$i);
            self::assertStringContainsString('deux '.$i, $preview->eq(0)->text());
            self::assertStringContainsString('trois '.$i, $preview->eq(1)->text());
            self::assertSelectorTextContains('#post-'.$post->getPublicId(), 'Voir les 3 commentaires');
        }
        self::assertCount(0, $crawler->filter('#post-'.$quiet->getPublicId().'-comments .cmt'));

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        /** @var list<array{sql: string}> $queries */
        $queries = $collector->getQueries()['default'];
        $commentQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['sql'], 'FROM comments'));
        self::assertCount(1, $commentQueries, 'Une seule requête pour les aperçus de toute la page.');
    }
}
