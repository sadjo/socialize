<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Grille du profil : pages chargées au défilement, sans sous-grilles imbriquées (WebKit en doublait le coût à chaque
 * page, jusqu'à bloquer le défilement sur iPhone au-delà de quelques centaines de publications).
 */
final class ProfileGridTest extends AppWebTestCase
{
    public function testThePagesOfTheGridLoadOneAfterAnotherInASingleGrid(): void
    {
        $camille = $this->register('camille');
        $composer = self::getContainer()->get(PostComposer::class);
        for ($i = 1; $i <= 26; ++$i) {
            $composer->publish($camille->getAccount(), 'Photo '.$i, [['file' => $this->jpeg(40, 50), 'alt' => null]]);
        }

        $crawler = $this->client->request('GET', '/@camille');
        self::assertCount(24, $crawler->filter('.grid3 .gt'));
        $next = $crawler->filter('.grid3 turbo-frame turbo-frame[loading="lazy"]');
        self::assertCount(1, $next, 'Page suivante chargée au défilement.');

        $this->client->request('GET', (string) $next->attr('src'));
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.gt');
        self::assertSelectorNotExists('turbo-frame turbo-frame', 'Dernière page.');

        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');
        self::assertStringContainsString('.grid3 turbo-frame{display:contents}', $css, 'Cadres chargés effacés de la mise en page.');
        self::assertStringNotContainsString('subgrid', $css, 'Pas de sous-grilles imbriquées.');
    }
}
