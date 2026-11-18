<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Media\MediaAttachment;
use App\Media\Application\ImageSizes;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Photos en plusieurs tailles : le navigateur prend celle qui correspond à l'affichage (srcset), au lieu de réduire
 * fortement la grande copie, ce qui la fait scintiller et paraître moins nette.
 */
final class ResponsiveImagesTest extends AppWebTestCase
{
    public function testFeedsAndGridsOfferSeveralSizes(): void
    {
        $camille = $this->login($this->register('camille'));
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Moto', [['file' => $this->jpeg(2000, 1500), 'alt' => null]]);
        $media = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $media);
        foreach (ImageSizes::names() as $name) {
            self::assertNotNull($media->getDerivative($name), $name);
        }

        // Fil : image entière en 640, 960 et 1440 pixels ; photo paysage (4:3), plus large que le cadre 4:5.
        $crawler = $this->client->request('GET', '/');
        $img = $crawler->filter('article.post .media img')->first();
        self::assertMatchesRegularExpression('#-large_640\.webp 640w, \S+-large_960\.webp 960w, \S+-large\.webp 1440w$#', (string) $img->attr('srcset'));
        self::assertSame('(max-width: 767px) calc(100vw * 1.67), calc(500px * 1.67)', $img->attr('sizes'));

        // Page de la publication : cadre plus large.
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertStringContainsString('calc(560px * 1.67)', (string) $crawler->filter('.pd .media img')->attr('sizes'));

        // Grille du profil : vignettes en 240, 360, 480 et 720 pixels.
        $crawler = $this->client->request('GET', '/@camille');
        $thumb = $crawler->filter('.grid3 .gt img')->first();
        self::assertMatchesRegularExpression('#-thumb_240\.webp 240w, \S+-thumb_360\.webp 360w, \S+-thumb\.webp 480w, \S+-thumb_720\.webp 720w$#', (string) $thumb->attr('srcset'));
        self::assertSame('(max-width: 767px) 34vw, 310px', $thumb->attr('sizes'));
    }

    public function testASmallPhotoIsNotOfferedInDuplicateSizes(): void
    {
        $camille = $this->login($this->register('camille'));
        self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Petite', [['file' => $this->jpeg(400, 500), 'alt' => null]]);

        $crawler = $this->client->request('GET', '/');
        self::assertNull($crawler->filter('article.post .media img')->first()->attr('srcset'), 'Une seule taille réelle : le src suffit.');
        self::assertSame(400, ImageSizes::actualWidth('large_960', 400, 500));
    }

    public function testTheCommandAddsTheMissingSizesToAlreadyPublishedMedia(): void
    {
        $camille = $this->register('camille');
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Ancienne', [['file' => $this->jpeg(1600, 2000), 'alt' => null]]);
        $media = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $media);
        // Média publié avant les nouvelles tailles : seulement la grande copie et la vignette.
        $media->markReady(1600, 2000, [
            MediaAttachment::DERIVATIVE_LARGE => (string) $media->getDerivative(MediaAttachment::DERIVATIVE_LARGE),
            MediaAttachment::DERIVATIVE_THUMB => (string) $media->getDerivative(MediaAttachment::DERIVATIVE_THUMB),
        ], ['Model' => 'X100V']);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->flush();
        $storage = self::getContainer()->get('derivatives.storage');
        $storage->delete($media->getCreatedAt()->format('Y/m').'/'.$media->getId()->toBase58().'-large_640.webp');

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:media:complete-sizes'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 média vérifié, 1 complété (5 copies ajoutées)', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');

        $em->clear();
        $media = $em->find(MediaAttachment::class, $media->getId());
        self::assertInstanceOf(MediaAttachment::class, $media);
        foreach (ImageSizes::names() as $name) {
            $path = $media->getDerivative($name);
            self::assertNotNull($path, $name);
            self::assertTrue($storage->fileExists($path), $name);
        }
        self::assertSame(['Model' => 'X100V'], $media->getExif(), 'L’EXIF déjà connu est gardé.');

        $tester->execute([]);
        self::assertStringContainsString('0 complété', $tester->getDisplay(), 'Relancée, la commande ne refait rien.');
    }
}
