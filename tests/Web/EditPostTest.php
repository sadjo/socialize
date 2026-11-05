<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Media\Application\PostEditor;
use App\Tests\AppWebTestCase;

final class EditPostTest extends AppWebTestCase
{
    public function testReorderRemoveAddAndDescribeMediaThenChangeVisibility(): void
    {
        $camille = $this->login($this->register('camille'));
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Avant', [
            ['file' => $this->jpeg(200, 250, 'a.jpg'), 'alt' => 'A'],
            ['file' => $this->jpeg(300, 250, 'b.jpg'), 'alt' => 'B'],
            ['file' => $this->jpeg(400, 250, 'c.jpg'), 'alt' => 'C'],
        ]);
        [$a, $b, $c] = $post->getMedia()->toArray();
        $removedOriginal = $b->getOriginalPath();
        $tokens = [PostEditor::token($a), PostEditor::token($b), PostEditor::token($c)];

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        self::assertResponseIsSuccessful();
        self::assertSame(implode(',', $tokens), $crawler->filter('input[name="edit_post[order]"]')->attr('value'));

        /** @var array{edit_post: array<string, mixed>} $values */
        $values = $crawler->selectButton('Enregistrer les modifications')->form()->getPhpValues();
        $values['edit_post']['caption'] = 'Après #montagne';
        $values['edit_post']['visibility'] = 'followers';
        $values['edit_post']['order'] = implode(',', [$tokens[2], 'n-0', $tokens[0]]);
        $values['edit_post']['altTexts'] = [$tokens[2] => 'C décrite', 'n-0' => 'Nouvelle', $tokens[0] => 'A'];
        $this->client->request('POST', '/p/'.$post->getPublicId().'/modifier', $values, ['edit_post' => ['media' => [$this->jpeg(500, 250, 'd.jpg')]]]);

        self::assertResponseRedirects('/p/'.$post->getPublicId());
        $post = $this->reload($post);
        self::assertSame('Après #montagne', $post->getCaption());
        self::assertSame(Visibility::Followers, $post->getVisibility());
        self::assertNotNull($post->getUpdatedAt());
        self::assertSame(
            [[400, 'C décrite'], [500, 'Nouvelle'], [200, 'A']],
            array_map(static fn (MediaAttachment $m): array => [$m->getWidth(), $m->getAltText()], $post->getMedia()->toArray()),
            'Les médias suivent l’ordre choisi, le retiré a disparu, le nouveau est à sa place.',
        );
        self::assertFalse(self::getContainer()->get('originals.storage')->fileExists($removedOriginal), 'Les fichiers du média retiré sont supprimés.');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.pd-side', 'modifié');
        self::assertSelectorExists('.slide img[alt="C décrite"]');
    }

    public function testAPublicationKeepsAtLeastOneMedia(): void
    {
        $camille = $this->login($this->register('camille'));
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Seule', [['file' => $this->jpeg(), 'alt' => null]]);

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(['edit_post[order]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->reload($post)->getMedia());
    }

    public function testOnlyTheAuthorCanEdit(): void
    {
        $camille = $this->register('camille');
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'À moi', [['file' => $this->jpeg(), 'alt' => null]]);

        $this->login($this->register('hugo'));
        $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorNotExists('a[href$="/modifier"]');
    }

    private function reload(Post $post): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(PostRepository::class)->find($post->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
