<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Médias d'une publication : même cadre 4:5 pour toutes, couvert par le média (en CSS), points du carrousel sous le
 * cadre.
 */
final class PostMediaFrameTest extends AppWebTestCase
{
    public function testTheDotsAreBelowTheFrame(): void
    {
        $camille = $this->login($this->register('camille'));
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Paysage', [
            ['file' => $this->jpeg(1200, 900), 'alt' => null],
            ['file' => $this->jpeg(600, 900), 'alt' => null],
        ]);

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertNull($crawler->filter('.media > .frame')->attr('style'), 'Cadre 4:5 commun, sans proportions propres.');
        self::assertCount(2, $crawler->filter('.media > .frame .slide'));
        self::assertCount(2, $crawler->filter('.media > .dots button'), 'Points sous le cadre, pas dedans.');
        $second = $crawler->filter('.media > .dots button')->eq(1);
        self::assertSame('carousel#show', $second->attr('data-action'), 'Point cliquable.');
        self::assertSame('1', $second->attr('data-carousel-index-param'));
        self::assertSame('Média 2 sur 2', $second->attr('aria-label'));
        self::assertSame('true', $crawler->filter('.media > .dots button')->first()->attr('aria-current'));
        self::assertCount(0, $crawler->filter('.frame .dots'));
        self::assertCount(1, $crawler->filter('.media > .frame .cnt'), 'Le compteur reste sur le média.');
    }
}
