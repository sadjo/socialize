<?php

declare(strict_types=1);

namespace App\Tests\Federation;

use App\Core\Domain\Post\LinkPreviewState;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use League\Flysystem\FilesystemOperator;

/**
 * Aperçu du premier lien d'une publication texte : page lue en tâche de fond, image copiée ici en WebP (les
 * visiteurs ne chargent rien depuis le site du lien), carte dans le fil et dans l'onglet « Textes » du profil.
 */
final class LinkPreviewTest extends AppWebTestCase
{
    public function testATextPostShowsAPreviewOfItsFirstLinkWithACopiedImage(): void
    {
        $post = $this->publish('À lire : https://remote.test/article et aussi https://remote.test/data.json');

        self::assertSame(LinkPreviewState::Ready, $post->getPreviewState());
        $preview = $post->getLinkPreview();
        self::assertNotNull($preview);
        self::assertSame('https://remote.test/article', $preview->url);
        self::assertSame('Le Journal', $preview->siteName());
        self::assertSame('Un article à lire', $preview->title);
        self::assertSame('Ce que dit l\'article, en deux lignes.', $preview->description);
        self::assertNotNull($preview->image);
        self::assertStringStartsWith('previews/', $preview->image);
        self::assertTrue($this->storage()->fileExists($preview->image), 'Image copiée ici.');

        // Fil : la carte de l'aperçu, avec l'image copiée.
        $this->client->request('GET', '/local');
        $card = '#post-'.$post->getPublicId().' .ptext a.lpv[href="https://remote.test/article"][rel~="nofollow"]';
        self::assertSelectorExists($card.' .lpv-img img[src="/m/'.$preview->image.'"]');
        self::assertSelectorTextContains($card.' .lpv-site', 'Le Journal');
        self::assertSelectorTextContains($card.' .lpv-t b', 'Un article à lire');

        // Onglet Textes du profil : le lien sur une ligne.
        $this->client->request('GET', '/@camille/textes');
        self::assertSelectorTextContains('a.tcard .lpv-chip', 'Le Journal · Un article à lire');

        // Publication supprimée : son image d'aperçu aussi.
        self::getContainer()->get(PostComposer::class)->delete($this->reload($post));
        self::assertFalse($this->storage()->fileExists($preview->image));
    }

    public function testNoPreviewForAnAddressThatIsNotAWebPage(): void
    {
        $post = $this->publish('Les données : https://remote.test/data.json');

        self::assertSame(LinkPreviewState::Failed, $post->getPreviewState());
        self::assertNull($post->getLinkPreview());
        $this->client->request('GET', '/local');
        self::assertSelectorNotExists('.lpv');
        self::assertSelectorExists('#post-'.$post->getPublicId().' .ptext-body a.link[href="https://remote.test/data.json"]', 'Le lien reste dans le texte.');
    }

    public function testNoPreviewForAPostWithPhotos(): void
    {
        $camille = $this->register('camille')->getAccount();
        $post = self::getContainer()->get(PostComposer::class)->publish($camille, 'Photo et lien https://remote.test/article', [['file' => $this->jpeg(40, 50), 'alt' => null]]);

        self::assertNull($this->reload($post)->getPreviewUrl(), 'La photo est le contenu : pas d’aperçu.');
    }

    private function publish(string $text): Post
    {
        $camille = $this->register('camille')->getAccount();

        return $this->reload(self::getContainer()->get(PostComposer::class)->publish($camille, $text, []));
    }

    private function reload(Post $post): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(PostRepository::class)->find($post->getId());
        self::assertInstanceOf(Post::class, $fresh);

        return $fresh;
    }

    private function storage(): FilesystemOperator
    {
        /** @var FilesystemOperator $storage */
        $storage = self::getContainer()->get('derivatives.storage');

        return $storage;
    }
}
