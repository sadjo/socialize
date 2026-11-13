<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Post\Visibility;
use App\Media\Application\PostComposer;
use App\Media\Application\StoryComposer;
use App\Tests\AppWebTestCase;
use Symfony\Component\HttpFoundation\File\File;

/**
 * « Partager vers Instagram » : bouton de l'auteur, fichiers JPEG ou MP4 réservés à l'auteur.
 */
final class ShareToInstagramTest extends AppWebTestCase
{
    public function testTheAuthorGetsJpegFilesAndTheCaption(): void
    {
        $camille = $this->login($this->register('camille'));
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Au bord de l’eau #lac', [
            ['file' => $this->jpeg(800, 600), 'alt' => null],
            ['file' => $this->jpeg(600, 800), 'alt' => null],
        ], Visibility::Followers);

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $button = $crawler->filter('[data-controller="share-to-instagram"]');
        self::assertCount(1, $button, 'Dans le menu de l’auteur.');
        self::assertSame('Au bord de l’eau #lac', $button->attr('data-share-to-instagram-caption-value'));
        /** @var list<array{url: string, name: string, type: string}> $files */
        $files = json_decode((string) $button->attr('data-share-to-instagram-files-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $files);
        self::assertSame('/p/'.$post->getPublicId().'/partage/1', $files[1]['url']);
        self::assertSame('image/jpeg', $files[1]['type']);

        $this->client->request('GET', $files[1]['url']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $size = getimagesizefromstring((string) $this->client->getResponse()->getContent());
        self::assertIsArray($size);
        self::assertSame(\IMAGETYPE_JPEG, $size[2], 'Une vraie image JPEG.');
        self::assertSame([600, 800], [$size[0], $size[1]]);

        // Un autre compte : ni bouton, ni fichiers.
        $this->login($this->register('hugo'));
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorNotExists('[data-controller="share-to-instagram"]');
        $this->client->request('GET', $files[0]['url']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/p/'.$post->getPublicId().'/partage/9');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAStoryCanBeSharedToo(): void
    {
        $camille = $this->login($this->register('camille'));
        [$story] = self::getContainer()->get(StoryComposer::class)->publish($camille->getAccount(), [new File($this->jpeg(540, 960)->getPathname())], 'Bonjour');
        $crawler = $this->client->request('GET', '/stories/mes-stories');
        $button = $crawler->filter('[data-controller="share-to-instagram"]');
        self::assertCount(1, $button);
        self::assertSame('Bonjour', $button->attr('data-share-to-instagram-caption-value'));

        $this->client->request('GET', '/stories/'.$story->getPublicId().'/partage');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
    }
}
