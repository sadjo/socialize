<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaStatus;
use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Media\Application\ProcessVideo;
use App\Media\Application\ProcessVideoHandler;
use App\Tests\AppWebTestCase;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PublishTest extends AppWebTestCase
{
    public function testPublishACarouselWithAltTextsAndCaption(): void
    {
        $this->login($this->register('camille'));

        $crawler = $this->client->request('GET', '/publier');
        /** @var array{compose_post: array<string, mixed>} $values */
        $values = $crawler->selectButton('Publier')->form()->getPhpValues();
        $values['compose_post']['caption'] = 'Dernière baignade #normandie avec @hugo';
        $values['compose_post']['location'] = 'Étretat';
        $values['compose_post']['visibility'] = 'public';
        $values['compose_post']['altTexts'] = ['Falaise au coucher du soleil', 'Plage de galets'];

        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg(), $this->jpeg(1200, 800)]]]);

        self::assertResponseRedirects();
        $post = $this->onlyPost();
        self::assertSame('Étretat', $post->getLocation());
        self::assertCount(2, $post->getMedia());

        $first = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $first);
        self::assertSame(MediaStatus::Ready, $first->getStatus());
        self::assertSame('Falaise au coucher du soleil', $first->getAltText());
        self::assertSame([800, 1000], [$first->getWidth(), $first->getHeight()]);
        self::assertSame('Fujifilm', $first->getExif()['ifd0-Make'] ?? null, 'L’EXIF de l’original est conservé.');
        self::assertNotNull($first->getBlurhash());

        $derivatives = $this->storage('derivatives');
        foreach ([MediaAttachment::DERIVATIVE_LARGE, MediaAttachment::DERIVATIVE_THUMB] as $name) {
            $path = $first->getDerivative($name);
            self::assertNotNull($path);
            self::assertTrue($derivatives->fileExists($path));
            self::assertStringNotContainsString('Fujifilm', $derivatives->read($path), 'Les copies publiques n’ont plus de métadonnées.');
        }
        self::assertTrue($this->storage('originals')->fileExists($first->getOriginalPath()));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.slide img');
        self::assertSelectorExists('img[alt="Falaise au coucher du soleil"]');
        self::assertSelectorTextContains('.cmts', 'Dernière baignade');
        self::assertSelectorExists('.cmts .tag');

        $this->client->request('GET', '/');
        self::assertSelectorCount(1, 'article.post');
        $this->client->request('GET', '/@camille');
        self::assertSelectorCount(1, '.grid3 .gt');
        self::assertSelectorExists('.grid3 .gt .gi');
    }

    public function testTheMediaPickerIsWiredToTheComposeController(): void
    {
        $this->login($this->register('camille'));

        foreach (['/publier' => 'compose_post_media', '/stories/nouvelle' => 'compose_story_media'] as $url => $id) {
            $this->client->request('GET', $url);
            self::assertSelectorExists('form[data-controller="compose"]');
            self::assertSelectorExists(\sprintf('input#%s[type="file"][multiple][data-compose-target="input"][accept*="image/jpeg"]', $id), 'Sans cette cible, l’aperçu des médias ne s’affiche pas.');
            foreach (['previews', 'drop', 'submit', 'caption', 'counter'] as $target) {
                self::assertSelectorExists(\sprintf('[data-compose-target="%s"]', $target));
            }
        }
    }

    public function testAPublicationNeedsAtLeastOneMedia(): void
    {
        $this->login($this->register('camille'));

        $this->client->request('GET', '/publier');
        $this->client->submitForm('Publier', ['compose_post[caption]' => 'Sans photo']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors', 'Ajoutez au moins une photo ou une vidéo.');
    }

    public function testAVideoIsTranscodedInTheBackground(): void
    {
        $this->login($this->register('camille'));

        $crawler = $this->client->request('GET', '/publier');
        $values = $crawler->selectButton('Publier')->form()->getPhpValues();
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->mp4(sound: false)]]]);
        self::assertResponseRedirects();

        $post = $this->onlyPost();
        $video = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $video);
        self::assertSame(MediaStatus::Processing, $video->getStatus());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.processing', 'Vidéo en cours de préparation');

        // Le consommateur Messenger traiterait ce message en tâche de fond.
        $message = new ProcessVideo($video->getId()->toRfc4122());
        /** @var ProcessVideoHandler $handler */
        $handler = self::getContainer()->get(ProcessVideoHandler::class);
        $handler($message);

        $video = $this->onlyPost()->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $video);
        self::assertSame(MediaStatus::Ready, $video->getStatus(), (string) $video->getFailureReason());
        self::assertSame([320, 240], [$video->getWidth(), $video->getHeight()]);
        self::assertEqualsWithDelta(2.0, $video->getDuration() ?? 0, 0.3);
        self::assertTrue($this->storage('derivatives')->fileExists((string) $video->getDerivative(MediaAttachment::DERIVATIVE_VIDEO)));
        self::assertTrue($this->storage('derivatives')->fileExists((string) $video->getDerivative(MediaAttachment::DERIVATIVE_POSTER)));
        $copy = sys_get_temp_dir().'/'.uniqid('socialize-', true).'.mp4';
        file_put_contents($copy, $this->storage('derivatives')->read((string) $video->getDerivative(MediaAttachment::DERIVATIVE_VIDEO)));
        self::assertTrue(self::getContainer()->get(\App\Media\Infrastructure\FfmpegVideoProcessor::class)->hasAudio($copy), 'Une vidéo sans son reçoit une piste muette.');
        unlink($copy);

        $this->client->request('GET', '/p/'.$this->onlyPost()->getPublicId());
        self::assertSelectorExists('video source[type="video/mp4"]');

        // Pour le fédivers : la vidéo est accompagnée de son affiche, que Mastodon utilise comme vignette.
        $this->client->request('GET', '/p/'.$this->onlyPost()->getPublicId(), server: ['HTTP_ACCEPT' => 'application/activity+json']);
        $note = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($note);
        self::assertIsArray($note['attachment'] ?? null);
        $attachment = $note['attachment'][0] ?? null;
        self::assertIsArray($attachment);
        self::assertSame('Video', $attachment['type'] ?? null);
        self::assertIsArray($attachment['icon'] ?? null);
        self::assertSame('http://localhost/m/'.$video->getDerivative(MediaAttachment::DERIVATIVE_POSTER), $attachment['icon']['url'] ?? null);
    }

    public function testPublishingAVideoQueuesItsTranscoding(): void
    {
        $author = $this->register('camille')->getAccount();
        /** @var PostComposer $composer */
        $composer = self::getContainer()->get(PostComposer::class);
        $post = $composer->publish($author, 'Timelapse', [['file' => $this->mp4(), 'alt' => null], ['file' => $this->jpeg(), 'alt' => null]]);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent, 'Seule la vidéo part en tâche de fond.');
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ProcessVideo::class, $message);
        $video = $post->getMedia()->first();
        self::assertInstanceOf(MediaAttachment::class, $video);
        self::assertSame($video->getId()->toRfc4122(), $message->mediaId);
    }

    public function testOnlyTheAuthorCanDeleteAPublication(): void
    {
        $this->login($this->register('camille'));
        $crawler = $this->client->request('GET', '/publier');
        $values = $crawler->selectButton('Publier')->form()->getPhpValues();
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg()]]]);
        $post = $this->onlyPost();
        $originalPath = $post->getMedia()->first() instanceof MediaAttachment ? $post->getMedia()->first()->getOriginalPath() : '';

        $this->login($this->register('hugo'));
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorNotExists('form[action$="/supprimer"]');

        $this->login($this->userOf('camille'));
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());

        self::assertResponseRedirects('/@camille');
        self::assertSame([], self::getContainer()->get(PostRepository::class)->findAll());
        self::assertFalse($this->storage('originals')->fileExists($originalPath));
    }

    private function onlyPost(): Post
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $posts = self::getContainer()->get(PostRepository::class)->findAll();
        self::assertCount(1, $posts);

        return $posts[0];
    }

    private function userOf(string $username): \App\Core\Domain\Account\User
    {
        $user = self::getContainer()->get(\App\Core\Infrastructure\Doctrine\UserRepository::class)->findOneByEmail($username.'@exemple.test');
        self::assertNotNull($user);

        return $user;
    }

    private function storage(string $name): FilesystemOperator
    {
        $storage = self::getContainer()->get($name.'.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }
}
