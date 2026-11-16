<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Integration\Mastodon\Domain\MastodonPublication;
use App\Integration\Mastodon\Domain\MastodonPublicationState;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonPublicationRepository;
use App\Tests\AppWebTestCase;
use App\Tests\Federation\FakeFediverse;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Publication sur son compte Mastodon depuis Socialize : médias et statut envoyés à son serveur, en tâche de fond.
 */
final class MastodonPublishTest extends AppWebTestCase
{
    use ClockSensitiveTrait;
    use ConnectsWithMastodon;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
        FakeFediverse::$outbox = [];
    }

    public function testAPostIsPublishedOnTheMastodonAccount(): void
    {
        $this->connect();
        $this->client->request('GET', '/');
        self::assertSelectorExists('.side a[href="/publier/mastodon"]');

        $crawler = $this->client->request('GET', '/publier/mastodon');
        self::assertSelectorTextContains('.page-h', 'jusqu’à 4 photos ou vidéos et 500 caractères');
        self::assertSelectorNotExists('.src-tabs', 'Ni Immich ni Synology Photos pour un compte Mastodon.');
        $this->submit($crawler, [$this->jpeg(400, 500, 'phare.jpg'), $this->jpeg(500, 400, 'plage.jpg')], [
            'order' => 'n-1,n-0',
            'altTexts' => ['n-0' => 'Le phare au couchant'],
            'caption' => 'Bretagne #photo',
            'visibility' => 'unlisted',
            'contentWarning' => 'Lumière vive',
        ]);
        self::assertResponseRedirects('/@alice@remote.test');

        // Médias dans l'ordre choisi, avec leur texte alternatif, puis le statut.
        self::assertSame([['type' => 'image/jpeg', 'description' => null], ['type' => 'image/jpeg', 'description' => 'Le phare au couchant']], FakeMastodon::$media);
        $status = FakeMastodon::requestsTo('POST', '/api/v1/statuses')[0]['body'] ?? [];
        self::assertSame(['media-1', 'media-2'], $status['media_ids'] ?? null);
        self::assertSame('Bretagne #photo', $status['status'] ?? null);
        self::assertSame('unlisted', $status['visibility'] ?? null);
        self::assertSame('Lumière vive', $status['spoiler_text'] ?? null);
        self::assertTrue($status['sensitive'] ?? null);

        $publication = $this->publication();
        self::assertSame(MastodonPublicationState::Sent, $publication->getState());
        self::assertSame('https://remote.test/users/alice/statuses/100', $publication->getStatusUri());
        self::assertSame([], glob(__DIR__.'/../../var/test-storage/mastodon/*') ?: [], 'Les fichiers envoyés sont supprimés.');
    }

    public function testAVideoIsWaitedForUntilTheServerHasProcessedIt(): void
    {
        self::mockTime('2026-11-17 10:00:00');
        FakeMastodon::$deferredMedia = true;
        $this->connect();
        $this->submit($this->client->request('GET', '/publier/mastodon'), [$this->mp4()], ['caption' => 'Vagues']);

        self::assertCount(1, FakeMastodon::requestsTo('GET', '/api/v1/media/media-1'));
        self::assertSame(['media-1'], FakeMastodon::requestsTo('POST', '/api/v1/statuses')[0]['body']['media_ids'] ?? null);
        self::assertSame(MastodonPublicationState::Sent, $this->publication()->getState());
    }

    public function testTheServerLimitsAreChecked(): void
    {
        FakeMastodon::$maxMedia = 1;
        $this->connect();
        $this->submit($this->client->request('GET', '/publier/mastodon'), [$this->jpeg(), $this->jpeg()], ['caption' => 'Deux photos']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors, .form-errors', '1 médias au plus');

        $this->submit($this->client->request('GET', '/publier/mastodon'), [], ['caption' => 'Sans photo']);
        self::assertSelectorTextContains('.form-errors', 'Ajoutez au moins une photo ou une vidéo.');
        self::assertSame([], FakeMastodon::$media);
    }

    public function testAFailedSendingIsShownOnTheProfile(): void
    {
        FakeMastodon::$statusError = 'Validation failed: Text character limit of 500 exceeded';
        $this->connect();
        $this->submit($this->client->request('GET', '/publier/mastodon'), [$this->jpeg()], ['caption' => 'Refusée']);
        self::assertSame(MastodonPublicationState::Failed, $this->publication()->getState());
        self::assertSame([], glob(__DIR__.'/../../var/test-storage/mastodon/*') ?: [], 'Les fichiers sont supprimés, même en cas d’échec.');

        $this->client->request('GET', '/@alice@remote.test');
        self::assertSelectorTextContains('.pending-posts', 'non publiée : remote.test a refusé : Validation failed');
    }

    public function testDeletingOwnPostDeletesItOnTheMastodonServer(): void
    {
        FakeFediverse::$outbox = [['id' => 'https://remote.test/notes/1/activity', 'type' => 'Create', 'actor' => FakeFediverse::ALICE, 'object' => [
            'id' => 'https://remote.test/notes/1', 'type' => 'Note', 'attributedTo' => FakeFediverse::ALICE, 'content' => '<p>Lever de soleil</p>',
            'published' => new \DateTimeImmutable('-1 day')->format(\DATE_ATOM), 'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://remote.test/media/1.jpg', 'width' => 1080, 'height' => 1350]],
        ]]];
        $this->connect();
        $post = self::getContainer()->get(PostRepository::class)->findOneBy(['apUri' => 'https://remote.test/notes/1']);
        self::assertInstanceOf(Post::class, $post);

        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->filter('form[action="/p/'.$post->getPublicId().'/supprimer"]')->form());
        self::assertCount(1, FakeMastodon::requestsTo('DELETE', '/api/v1/statuses/'.FakeMastodon::idOf('https://remote.test/notes/1')));
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNull(self::getContainer()->get(PostRepository::class)->findOneBy(['apUri' => 'https://remote.test/notes/1']));
    }

    /**
     * @param list<UploadedFile>   $files
     * @param array<string, mixed> $fields
     */
    private function submit(\Symfony\Component\DomCrawler\Crawler $crawler, array $files, array $fields): void
    {
        /** @var array{mastodon_compose: array<string, mixed>} $values */
        $values = $crawler->selectButton('Publier sur remote.test')->form()->getPhpValues();
        $values['mastodon_compose'] = $fields + $values['mastodon_compose'];
        $this->client->request('POST', '/publier/mastodon', $values, ['mastodon_compose' => ['media' => $files]]);
    }

    private function publication(): MastodonPublication
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $publication = self::getContainer()->get(MastodonPublicationRepository::class)->findOneBy([]);
        self::assertInstanceOf(MastodonPublication::class, $publication);

        return $publication;
    }
}
