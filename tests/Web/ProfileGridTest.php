<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Grille du profil : pages chargées au défilement dans une seule grille plate, sans cadres ni sous-grilles imbriqués
 * (WebKit en doublait le coût à chaque page, jusqu'à bloquer le défilement sur iPhone au-delà de quelques centaines de
 * publications).
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
        self::assertCount(24, $crawler->filter('.grid3[data-controller="infinite-list"] > .gt'));
        self::assertSelectorNotExists('.grid3 turbo-frame', 'Plus de cadres Turbo dans la grille.');
        $next = $crawler->filter('.grid3 > #grid-more[data-infinite-list-target="more"] a');
        self::assertCount(1, $next, 'Repère de la page suivante, en fin de grille.');

        // Suite en flux Turbo : seulement les vignettes, insérées avant le repère, qui disparaît en fin de grille.
        $this->client->request('GET', (string) $next->attr('href'), server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        self::assertSelectorCount(2, 'turbo-stream[action="before"][target="grid-more"] .gt');
        self::assertSelectorExists('turbo-stream[action="remove"][target="grid-more"]');
        self::assertSelectorNotExists('.grid3', 'Pas le reste du profil.');

        // Sans JavaScript, le lien ouvre le profil, grille commencée à la suite.
        $this->client->request('GET', (string) $next->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.grid3 > .gt');
        self::assertSelectorNotExists('#grid-more');

        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');
        self::assertStringNotContainsString('subgrid', $css, 'Pas de sous-grilles imbriquées.');
    }
}
