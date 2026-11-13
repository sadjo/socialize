<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Médias d'une publication montrés en entier : cadre aux proportions du premier média (4:5 à 1,91:1) sur ordinateur,
 * 4:5 pour toutes sur mobile ; points du carrousel sous le cadre.
 */
final class PostMediaFrameTest extends AppWebTestCase
{
    public function testTheFrameFollowsTheFirstMediaAndTheDotsAreBelowIt(): void
    {
        $camille = $this->login($this->register('camille'));
        $composer = self::getContainer()->get(PostComposer::class);
        $landscape = $composer->publish($camille->getAccount(), 'Paysage', [
            ['file' => $this->jpeg(1200, 900), 'alt' => null],
            ['file' => $this->jpeg(600, 900), 'alt' => null],
        ]);

        $crawler = $this->client->request('GET', '/p/'.$landscape->getPublicId());
        self::assertSame('--ratio: 1.3333', $crawler->filter('.media > .frame')->attr('style'), 'Proportions du premier média, sur ordinateur (mobile : 4:5, en CSS).');
        self::assertCount(2, $crawler->filter('.media > .frame .slide'));
        self::assertCount(2, $crawler->filter('.media > .dots i'), 'Points sous le cadre, pas dedans.');
        self::assertCount(0, $crawler->filter('.frame .dots'));
        self::assertCount(1, $crawler->filter('.media > .frame .cnt'), 'Le compteur reste sur le média.');

        // Très vertical (2:5) ou très large (3:1) : ramené à 4:5 ou 1,91:1, le média restant entier dans le cadre.
        foreach ([[400, 1000, '0.8000'], [1500, 500, '1.9100']] as [$width, $height, $ratio]) {
            $post = self::getContainer()->get(PostComposer::class)->publish($this->reloadAccount($camille), 'Format', [['file' => $this->jpeg($width, $height), 'alt' => null]]);
            $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
            self::assertSame('--ratio: '.$ratio, $crawler->filter('.media > .frame')->attr('style'), $width.'×'.$height);
            self::assertCount(0, $crawler->filter('.media .dots'), 'Un seul média : pas de points.');
        }
    }

    private function reloadAccount(\App\Core\Domain\Account\User $user): \App\Core\Domain\Account\Account
    {
        $fresh = self::getContainer()->get(\App\Core\Infrastructure\Doctrine\UserRepository::class)->find($user->getId());
        self::assertInstanceOf(\App\Core\Domain\Account\User::class, $fresh);

        return $fresh->getAccount();
    }
}
