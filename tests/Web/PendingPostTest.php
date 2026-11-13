<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\MediaAttachmentRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Media\Application\PostEditor;
use App\Media\Application\ProcessVideo;
use App\Media\Application\ProcessVideoHandler;
use App\Media\Infrastructure\FfmpegVideoProcessor;
use App\Tests\AppWebTestCase;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Publication avec une vidéo : en attente le temps de la conversion (visible de son auteur seul, avec la progression),
 * puis publiée, et alors seulement envoyée au fédivers.
 */
final class PendingPostTest extends AppWebTestCase
{
    public function testAPostWithAVideoWaitsForItsConversionThenAppears(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        self::getContainer()->get(SocialActions::class)->follow($hugo->getAccount(), $camille->getAccount());

        $this->login($camille);
        $crawler = $this->client->request('GET', '/publier');
        $values = $crawler->selectButton('Publier')->form()->getPhpValues();
        self::assertIsArray($values['compose_post'] ?? null);
        $values['compose_post']['caption'] = 'Au bord du lac';
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->mp4()]]]);
        self::assertResponseRedirects('/@camille', message: 'En attente : direction le profil, où la progression est affichée.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Publication en préparation');
        self::assertSelectorTextContains('.pending-posts', 'Au bord du lac');
        self::assertSelectorTextContains('.pending-posts', 'en attente de conversion');
        self::assertSelectorExists('turbo-frame#pending-posts[data-controller="poll"][src="/@camille/en-preparation?affichees=1"]');

        $post = $this->onlyPost();
        self::assertTrue($post->isPending());
        self::assertFalse($post->isFederable(), 'Pas de fédivers avant la fin de la conversion.');
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.chip', 'En préparation');
        self::assertSelectorNotExists('.grid3 a[href*="'.$post->getPublicId().'"]', 'Pas dans la grille du profil.');

        // Les autres ne la voient nulle part.
        $this->login($hugo);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('#post-'.$post->getPublicId());
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/@camille');
        self::assertSelectorNotExists('.pending-posts');
        self::assertSelectorTextContains('.stats', '0 publication');
        $this->client->request('GET', '/@camille/en-preparation');
        self::assertResponseStatusCodeSame(404);

        // Progression enregistrée pendant la conversion, puis publication une fois la vidéo prête, en tête des fils.
        $video = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $video);
        self::getContainer()->get(MediaAttachmentRepository::class)->saveProgress($video, 42);
        $this->login($camille);
        $this->client->request('GET', '/@camille/en-preparation?affichees=1');
        self::assertSelectorTextContains('.pending-posts', '42 %');
        self::assertSelectorNotExists('[data-poll-done]');

        $createdAt = $post->getPublishedAt();
        // Comme dans un worker : le média est chargé avant que la progression soit écrite en base.
        $repository = self::getContainer()->get(MediaAttachmentRepository::class);
        $repository->saveProgress($video, null);
        self::getContainer()->get('doctrine')->getManager()->clear();
        $video = $repository->find($video->getId());
        self::assertInstanceOf(MediaAttachment::class, $video);
        $repository->saveProgress($video, 60);
        self::getContainer()->get(ProcessVideoHandler::class)(new ProcessVideo($video->getId()->toRfc4122()));
        $post = $this->onlyPost();
        self::assertFalse($post->isPending());
        self::assertTrue($post->isFederable());
        self::assertGreaterThanOrEqual($createdAt, $post->getPublishedAt());
        $ready = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $ready);
        self::assertNull($ready->getProgress(), 'Progression effacée une fois la vidéo prête.');

        $this->client->request('GET', '/@camille/en-preparation?affichees=1');
        self::assertSelectorExists('[data-poll-done]', 'Une publication de moins : la page se recharge pour la montrer.');
        $this->login($hugo);
        $this->client->request('GET', '/');
        self::assertSelectorExists('#post-'.$post->getPublicId());
    }

    public function testAFailedConversionKeepsThePostWaitingUntilItIsEdited(): void
    {
        $camille = $this->login($this->register('camille'));
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Photo et vidéo', [
            ['file' => $this->jpeg(), 'alt' => null],
            ['file' => new File($this->mp4()->getPathname()), 'alt' => null],
        ]);
        self::assertTrue($post->isPending());
        $video = array_first(array_filter($post->getMedia()->toArray(), static fn (MediaAttachment $m): bool => $m->isVideo()));
        self::assertInstanceOf(MediaAttachment::class, $video);
        // Original illisible : la conversion échoue.
        $originals = self::getContainer()->get('originals.storage');
        $originals->write($video->getOriginalPath(), 'pas une vidéo');
        self::getContainer()->get(ProcessVideoHandler::class)(new ProcessVideo($video->getId()->toRfc4122()));
        self::assertTrue($this->onlyPost()->isPending(), 'Échec : la publication reste en attente.');

        $this->client->request('GET', '/@camille');
        self::assertSelectorTextContains('.pending-posts', 'la conversion a échoué');
        self::assertSelectorExists('.pending-posts a[href="/p/'.$post->getPublicId().'/modifier"]');
        self::assertSelectorNotExists('turbo-frame#pending-posts[data-controller="poll"]', 'Plus rien en conversion : pas de rechargement.');

        // La vidéo retirée en modifiant la publication : elle paraît.
        $post = $this->onlyPost();
        $photo = array_first(array_filter($post->getMedia()->toArray(), static fn (MediaAttachment $m): bool => !$m->isVideo()));
        self::assertInstanceOf(MediaAttachment::class, $photo);
        self::getContainer()->get(PostEditor::class)->edit($post, 'Photo seule', $post->getVisibility(), null, null, [PostEditor::token($photo)]);
        self::assertFalse($this->onlyPost()->isPending());
    }

    public function testTheConversionReportsItsProgress(): void
    {
        $input = $this->mp4()->getPathname();
        $output = sys_get_temp_dir().'/'.uniqid('socialize-progression-', true).'.mp4';
        $reported = [];
        self::getContainer()->get(FfmpegVideoProcessor::class)->transcode($input, $output, static function (int $percent) use (&$reported): void {
            $reported[] = $percent;
        });
        self::assertFileExists($output);
        unlink($output);
        self::assertNotSame([], $reported, 'Au moins une étape rapportée.');
        self::assertSame($reported, array_values(array_unique($reported)));
        self::assertLessThanOrEqual(99, max($reported));
    }

    public function testAPostWithoutVideoAppearsAtOnce(): void
    {
        $camille = $this->login($this->register('camille'));
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Photo', [['file' => $this->jpeg(), 'alt' => null]]);
        self::assertFalse($post->isPending());
        self::assertTrue($post->isFederable());
    }

    private function onlyPost(): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $posts = self::getContainer()->get(PostRepository::class)->findAll();
        self::assertCount(1, $posts);

        return $posts[0];
    }
}
