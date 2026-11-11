<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Media\MediaAttachment;
use App\Media\Application\PostComposer;
use App\Media\Application\ProcessVideo;
use App\Media\Application\ProcessVideoHandler;
use App\Tests\AppWebTestCase;

/**
 * Visionneuse des médias d'une publication : chaque photo et vidéo prête y est proposée, avec sa taille d'origine.
 */
final class LightboxTest extends AppWebTestCase
{
    public function testEveryReadyPhotoAndVideoCanBeOpenedInTheViewer(): void
    {
        $author = $this->register('camille')->getAccount();
        $post = self::getContainer()->get(PostComposer::class)->publish($author, 'Carnet de voyage', [
            ['file' => $this->jpeg(1200, 800), 'alt' => 'Plage au lever du soleil'],
            ['file' => $this->mp4(), 'alt' => null],
            ['file' => $this->jpeg(800, 1000), 'alt' => null],
        ]);
        $video = $post->getMedia()->get(1);
        self::assertInstanceOf(MediaAttachment::class, $video);
        self::getContainer()->get(ProcessVideoHandler::class)(new ProcessVideo($video->getId()->toRfc4122()));

        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.media[data-controller~="lightbox"][data-action~="dblclick->lightbox#cancel"]', 'Un double-clic (J’aime) n’ouvre pas la visionneuse.');
        self::assertSelectorCount(3, '.slide[data-lightbox-target="item"][data-action="click->lightbox#open"]');
        self::assertSelectorExists('.slide[data-lightbox-type="image"][data-lightbox-width="1200"][data-lightbox-height="800"][data-lightbox-alt="Plage au lever du soleil"][data-lightbox-src$=".webp"]');
        self::assertSelectorExists('.slide[data-lightbox-type="video"][data-lightbox-src$="-video.mp4"][data-lightbox-poster$=".webp"]');
        self::assertSelectorExists('.slide[data-lightbox-type="image"][data-lightbox-alt="Photo 3 sur 3"]');
        self::assertSelectorNotExists('video[data-action*="video#open"]', 'La vidéo s’ouvre dans la même visionneuse que les photos.');

        // Carrousel : sur le premier média, pas de flèche vers l'arrière (le contrôleur gère ensuite les extrémités).
        self::assertSelectorExists('.media button.nb.prev[data-carousel-target="prev"][hidden]');
        self::assertSelectorExists('.media button.nb.next[data-carousel-target="next"]:not([hidden])');
    }
}
