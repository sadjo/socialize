<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Media\MediaAttachment;
use App\Media\Application\ImageSizes;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use App\Tests\Support\TestStorage;
use Jcupitt\Vips\Image;
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
        // Téléphone très dense (3x) : copie d'un écran 2x, pour ménager la mémoire de Safari sur iPhone.
        self::assertSame('(max-width: 767px) and (min-resolution: 2.5dppx) calc(67vw * 1.67), (max-width: 767px) calc(100vw * 1.67), calc(500px * 1.67)', $img->attr('sizes'));

        // Page de la publication : cadre plus large.
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertStringContainsString('calc(560px * 1.67)', (string) $crawler->filter('.pd .media img')->attr('sizes'));

        // Grille du profil : vignettes en 240, 360, 480 et 720 pixels.
        $crawler = $this->client->request('GET', '/@camille');
        $thumb = $crawler->filter('.grid3 .gt img')->first();
        self::assertMatchesRegularExpression('#-thumb_240\.webp 240w, \S+-thumb_360\.webp 360w, \S+-thumb\.webp 480w, \S+-thumb_720\.webp 720w$#', (string) $thumb->attr('srcset'));
        self::assertSame('(max-width: 767px) 34vw, 310px', $thumb->attr('sizes'));
    }

    /**
     * Carrousel : seule la première photo est chargée avec la page ; les suivantes attendent leur tour (data-src),
     * chargées par carousel_controller à l'approche.
     */
    public function testOnlyTheFirstPhotoOfACarouselIsLoadedWithThePage(): void
    {
        $camille = $this->login($this->register('camille'));
        self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Série', [
            ['file' => $this->jpeg(1600, 2000), 'alt' => 'Un'],
            ['file' => $this->jpeg(1600, 2000), 'alt' => 'Deux'],
            ['file' => $this->jpeg(1600, 2000), 'alt' => 'Trois'],
        ]);

        $crawler = $this->client->request('GET', '/');
        $images = $crawler->filter('article.post .media .slide img');
        self::assertCount(3, $images);
        self::assertNotNull($images->eq(0)->attr('src'));
        self::assertNotNull($images->eq(0)->attr('srcset'));
        foreach ([1, 2] as $i) {
            self::assertNull($images->eq($i)->attr('src'), 'Photo '.($i + 1).' sans src.');
            self::assertNull($images->eq($i)->attr('srcset'));
            self::assertStringEndsWith('-large.webp', (string) $images->eq($i)->attr('data-src'));
            self::assertStringContainsString('-large_960.webp 960w', (string) $images->eq($i)->attr('data-srcset'));
        }
        // La visionneuse garde l'adresse de chaque photo.
        self::assertSelectorCount(3, '.slide[data-lightbox-src$="-large.webp"]');
    }

    public function testASmallPhotoIsNotOfferedInDuplicateSizes(): void
    {
        $camille = $this->login($this->register('camille'));
        self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Petite', [['file' => $this->jpeg(400, 500), 'alt' => null]]);

        $crawler = $this->client->request('GET', '/');
        self::assertNull($crawler->filter('article.post .media img')->first()->attr('srcset'), 'Une seule taille réelle : le src suffit.');
        self::assertSame(400, ImageSizes::actualWidth('large_960', 400, 500));
    }

    public function testTheThumbnailsOfASmallPhotoAreNotEnlarged(): void
    {
        $camille = $this->login($this->register('camille'));
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Petite', [['file' => $this->jpeg(200, 300), 'alt' => null]]);
        $media = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $media);

        // Plus grand recadrage 4:5 de l'original (200×250) pour les vignettes plus grandes, 4:5 gardé.
        foreach (ImageSizes::widths(ImageSizes::THUMB) as $name => $width) {
            $file = Image::newFromFile(TestStorage::dir('public/'.$media->getDerivative($name)));
            self::assertSame([min($width, 200), min(intdiv($width * 5, 4), 250)], [$file->width, $file->height], $name);
        }
        self::assertSame([200, 250, true], ImageSizes::actualBox('thumb_720', 200, 300));
        self::assertSame([240, 300, true], ImageSizes::actualBox('thumb_240', 1600, 2000), 'Grande image : taille prévue.');
        self::assertSame(200, ImageSizes::actualWidth('thumb_360', 200, 300));

        // Toutes les vignettes font la même largeur : une seule suffit, sans srcset.
        $crawler = $this->client->request('GET', '/@camille');
        self::assertNull($crawler->filter('.grid3 .gt img')->first()->attr('srcset'));
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
