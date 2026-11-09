<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\PostSource;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Import\Instagram\Domain\ImportItem;
use App\Import\Instagram\Domain\ImportStatus;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Infrastructure\Doctrine\ImportItemRepository;
use App\Import\Instagram\Infrastructure\Doctrine\InstagramImportRepository;
use App\Tests\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportTest extends AppWebTestCase
{
    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archive = InstagramArchive::build(fn (): \Symfony\Component\HttpFoundation\File\UploadedFile => $this->jpeg(400, 500));
    }

    protected function tearDown(): void
    {
        @unlink($this->archive);
        parent::tearDown();
    }

    public function testTheArchiveIsSentInChunksResumedAfterACutAndAnalyzed(): void
    {
        $this->login($this->register('camille'));
        $token = $this->uploadToken();
        $size = (int) filesize($this->archive);
        $bytes = (string) file_get_contents($this->archive);

        $start = $this->begin($token, $size);
        self::assertSame(0, $start['parts'][0]['received']);
        $url = $start['parts'][0]['url'];

        $this->chunk($token, $url, 0, substr($bytes, 0, 1000));
        self::assertResponseIsSuccessful();
        self::assertSame(1000, $this->json()['received']);

        // Coupure : le même morceau renvoyé est refusé, le serveur indique où reprendre.
        $this->chunk($token, $url, 0, substr($bytes, 0, 1000));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1000, $this->json()['received']);

        // Nouvelle page, mêmes fichiers : l'envoi reprend.
        $this->client->request('GET', '/import');
        self::assertSelectorTextContains('.note', 'Envoi interrompu');
        $resume = $this->begin($token, $size);
        self::assertSame($start['id'], $resume['id']);
        self::assertSame(1000, $resume['parts'][0]['received']);

        $this->chunk($token, $url, 1000, substr($bytes, 1000));
        self::assertSame($size, $this->json()['received']);

        $crawler = $this->client->request('GET', $start['show_url']);
        // Publications, archivées, stories, photos et vidéos.
        self::assertSame(['3', '1', '1', '7'], $crawler->filter('.kpi b')->each(static fn ($node): string => $node->text()));
        self::assertSelectorTextContains('main', '1 média absent de l’archive');
        self::assertSelectorTextContains('main', 'encodage cassé');
        self::assertSelectorExists('input[name="import_options[placement]"][value="public_quiet"][checked]');

        $items = self::getContainer()->get(ImportItemRepository::class)->findAll();
        self::assertCount(5, $items, 'Les messages privés de l’archive sont ignorés.');
    }

    public function testUploadsAreReservedToTheirOwnerAndLimitedInSize(): void
    {
        $this->login($this->register('camille'));
        $token = $this->uploadToken();
        $start = $this->begin($token, (int) filesize($this->archive));

        $this->client->request('POST', '/import/envoi', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'faux'], content: '{"parts":[{"name":"a.zip","size":10}]}');
        self::assertResponseStatusCodeSame(403);

        $this->login($this->register('hugo'));
        $token = $this->uploadToken();
        $this->chunk($token, $start['parts'][0]['url'], 0, 'abc');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $start['show_url']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/import/envoi', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"parts":[{"name":"instagram.zip","size":30000000000}]}');
        self::assertResponseStatusCodeSame(422);
        $error = $this->json()['error'] ?? null;
        self::assertIsString($error);
        self::assertStringContainsString('taille maximale', $error);

        $this->client->request('POST', '/import/envoi', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"parts":[{"name":"photo.jpg","size":10}]}');
        self::assertResponseStatusCodeSame(422, 'Seules les archives .zip sont acceptées.');
    }

    public function testImportWithChoicesPublicationByPublication(): void
    {
        $this->login($this->register('camille'));
        $import = $this->uploaded();

        // Étape 3 : la visibilité de tout l'import, puis une liste déroulante par publication.
        $crawler = $this->client->request('GET', '/import/'.$import->getPublicId());
        self::assertSelectorExists('input[name="import_options[placement]"][value="public_quiet"][checked]');
        self::assertSelectorCount(4, '#publications .import-item select');
        self::assertSelectorTextContains('#publications select option[selected]', 'Visibilité de l’import');
        self::assertSelectorExists('.import-item img[src*="/apercu/"]');
        self::assertSelectorExists('#publications select option[value="archive"][selected]', 'La publication archivée sur Instagram est proposée dans les archives.');
        self::assertSelectorNotExists('input[type="checkbox"][name^="cochees"]', 'Pas de sélection groupée : une liste par publication.');

        $winter = $this->item($import, InstagramArchive::WINTER);
        $spring = $this->item($import, InstagramArchive::SPRING);
        $this->client->request('GET', '/import/'.$import->getPublicId().'/apercu/'.$winter->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/webp');

        // Un seul envoi : visibilité de l'import, choix par publication et lancement.
        $this->client->submit($crawler->selectButton('Lancer l’import')->form([
            'import_options[placement]' => 'public_quiet',
            'choix['.$winter->getPublicId().']' => 'skip',
            'choix['.$spring->getPublicId().']' => 'followers',
        ]));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('h2', 'Import terminé');
        self::assertSelectorTextContains('.log', 'Import terminé');

        $posts = self::getContainer()->get(PostRepository::class);
        $summer = $this->post($import, InstagramArchive::SUMMER);
        self::assertSame('Été à Biarritz #surf 🌊', $summer->getCaption(), 'Encodage réparé.');
        self::assertSame(PostSource::InstagramImport, $summer->getSource());
        self::assertSame(Visibility::Public, $summer->getVisibility());
        self::assertFalse($summer->shouldAnnounce());
        self::assertSame(InstagramArchive::SUMMER, $summer->getPublishedAt()->getTimestamp(), 'Date d’origine conservée.');
        self::assertSame(InstagramArchive::SUMMER, $summer->getOriginalCreatedAt()?->getTimestamp());
        self::assertCount(2, $summer->getMedia());
        self::assertSame('43.4832', $summer->getCover()?->getExif()['gps-latitude'] ?? null, 'Position des métadonnées, gardée dans l’EXIF de l’original.');

        $spring = $this->post($import, InstagramArchive::SPRING);
        self::assertSame(Visibility::Followers, $spring->getVisibility());
        self::assertSame('Premier printemps', $spring->getCaption(), 'Légende portée par l’unique média.');
        self::assertCount(1, $spring->getMedia(), 'Le média absent de l’archive est ignoré.');

        self::assertNull($this->item($import, InstagramArchive::WINTER)->getTargetId(), 'Publication écartée : pas importée.');

        $archived = $this->post($import, InstagramArchive::ARCHIVED);
        self::assertTrue($archived->isArchived());

        $story = self::getContainer()->get(StoryRepository::class)->find($this->item($import, InstagramArchive::STORY)->getTargetId() ?? throw new \LogicException());
        self::assertInstanceOf(Story::class, $story);
        self::assertSame(InstagramArchive::STORY, $story->getCreatedAt()->getTimestamp());
        self::assertFalse($story->isActive(new \DateTimeImmutable()), 'Une story importée rejoint les archives.');
        $media = $story->getMedia();
        self::assertNotNull($media);
        self::assertSame('iPhone 11 back camera', $media->getExif()['instagram-lens_model'] ?? null);
        self::assertArrayNotHasKey('instagram-device_id', $media->getExif(), 'L’identifiant de l’appareil n’est pas gardé.');

        // Détail de l'import : chaque publication avec un lien vers elle, et l'import peut être fermé.
        $crawler = $this->client->request('GET', '/import/'.$import->getPublicId());
        self::assertSelectorTextContains('#detail h2', 'Publications (4)');
        self::assertSelectorExists('#detail a[href="/p/'.$summer->getPublicId().'"]');
        self::assertSelectorExists('#detail a[href="/p/'.$archived->getPublicId().'"]');
        self::assertSelectorTextContains('#detail', 'Non importée');
        self::assertSelectorExists('a[href="/stories/archives/'.$story->getPublicId().'"]', 'Les stories ont aussi leur lien.');
        $this->client->click($crawler->selectLink('Fermer l’import')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="chunked-upload"]', 'Un nouvel import peut être lancé.');
        self::assertSelectorTextContains('.zip', '4 importés, 1 non importé');
        $this->client->click($this->client->getCrawler()->selectLink('Détails')->link());
        self::assertSelectorExists('#detail a[href="/p/'.$spring->getPublicId().'"]', 'L’historique garde le détail de l’import.');

        // Profil : les publications visibles, à leur date d'origine ; l'archivée dans les archives.
        $this->client->request('GET', '/@camille');
        self::assertSelectorTextContains('.stats', '2 publications');
        $this->client->request('GET', '/p/'.$summer->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pbody .ago', 'publié à l’origine sur Instagram le 14 juin 2019');
        $this->client->request('GET', '/@camille/archives');
        self::assertSelectorExists('.grid3 a[href="/p/'.$archived->getPublicId().'"]');

        // L'archive reçue est supprimée, le JSON d'origine reste.
        self::assertDirectoryDoesNotExist(self::getContainer()->getParameter('app.import_dir').'/'.$import->getPublicId());
        self::assertSame('Neige', $this->item($import, InstagramArchive::WINTER)->getRaw()['title'] ?? null);
        self::assertCount(3, $posts->findBy(['source' => PostSource::InstagramImport]), 'La publication écartée n’est pas créée.');
    }

    public function testTheProgressFollowsTheImportAndItsVideosAfterAReload(): void
    {
        @unlink($this->archive);
        $this->archive = InstagramArchive::build(fn (): \Symfony\Component\HttpFoundation\File\UploadedFile => $this->jpeg(400, 500), fn (): \Symfony\Component\HttpFoundation\File\UploadedFile => $this->mp4());
        $this->login($this->register('camille'));
        $import = $this->uploaded();

        $crawler = $this->client->request('GET', '/import/'.$import->getPublicId());
        $this->client->submit($crawler->selectButton('Lancer l’import')->form());
        // Les vidéos partent en conversion une fois tous les éléments importés, en une fois (pas entre deux lots).
        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $queue */
        $queue = self::getContainer()->get('messenger.transport.async');
        $videos = array_filter($queue->getSent(), static fn (\Symfony\Component\Messenger\Envelope $envelope): bool => $envelope->getMessage() instanceof \App\Media\Application\ProcessVideo);
        self::assertCount(1, $videos);
        // Les vidéos sont converties en tâche de fond (non traitées dans les tests) : l'import est fini, pas les vidéos.
        $this->client->request('GET', '/import');
        self::assertResponseRedirects('/import/'.$import->getPublicId(), message: 'Recharger la page d’import ramène à sa progression.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h2', 'vidéos en cours de traitement');
        self::assertSelectorExists('turbo-frame#import-progress[data-controller="poll"][src$="/progression"]', 'La progression continue d’être rechargée.');
        self::assertSelectorExists('.prog[aria-label="Import des publications et des stories"][aria-valuenow="100"]');
        self::assertSelectorExists('.prog[aria-label="Traitement des vidéos"][aria-valuenow="0"]');
        self::assertSelectorTextContains('#import-progress', '0 vidéo prête sur 1');
        self::assertSelectorNotExists('a:contains("Fermer l’import")');
        self::assertSelectorTextContains('main', 'pourra être fermé une fois les vidéos converties');

        // Réponse du rechargement : la progression, sans src (Turbo viderait un cadre qui se désigne lui-même).
        $this->client->request('GET', '/import/'.$import->getPublicId().'/progression', server: ['HTTP_TURBO_FRAME' => 'import-progress']);
        self::assertSelectorExists('turbo-frame#import-progress:not([src])');
        self::assertSelectorExists('.prog[aria-label="Traitement des vidéos"]');

        // La vidéo est prête : la progression l'indique et cesse d'être rechargée.
        $video = $this->post($import, InstagramArchive::REEL)->getCover() ?? throw new \LogicException();
        self::getContainer()->get(\App\Media\Application\ProcessVideoHandler::class)(new \App\Media\Application\ProcessVideo($video->getId()->toRfc4122()));
        $this->client->request('GET', '/import/'.$import->getPublicId().'/progression');
        self::assertSelectorExists('[data-poll-done]');
        $this->client->request('GET', '/import/'.$import->getPublicId());
        self::assertSelectorExists('a[href="/import"]:contains("Fermer l’import")');
        $this->client->request('GET', '/import');
        self::assertResponseIsSuccessful('Tout est terminé : la page d’import propose un nouvel import.');
    }

    public function testReimportingTheSameArchiveCreatesNoDuplicate(): void
    {
        $this->register('camille');
        $console = new Application(self::$kernel ?? self::bootKernel());

        foreach ([1, 2] as $run) {
            $tester = new CommandTester($console->find('app:import:instagram'));
            $tester->execute(['identifier' => 'camille', 'archives' => [$this->archive], '--visibility' => 'private']);
            $tester->assertCommandIsSuccessful();
        }
        self::assertStringContainsString('5 éléments déjà importés', $tester->getDisplay());

        $posts = self::getContainer()->get(PostRepository::class)->findBy(['source' => PostSource::InstagramImport]);
        self::assertCount(4, $posts);
        self::assertSame([Visibility::Private], array_values(array_unique(array_map(static fn (Post $p): Visibility => $p->getVisibility(), $posts), \SORT_REGULAR)));
        self::assertCount(1, self::getContainer()->get(StoryRepository::class)->findAll());
        self::assertFileExists($this->archive, 'Une archive donnée en console n’est jamais supprimée.');
    }

    public function testAnArchiveWithoutPostsIsRefused(): void
    {
        $this->login($this->register('camille'));
        $zip = new \ZipArchive();
        $empty = sys_get_temp_dir().'/'.uniqid('vide-', true).'.zip';
        $zip->open($empty, \ZipArchive::CREATE);
        $zip->addFromString('index.html', '<html></html>');
        $zip->close();

        $import = $this->uploaded($empty);
        @unlink($empty);
        self::assertSame(ImportStatus::Failed, $import->getStatus());
        $this->client->request('GET', '/import/'.$import->getPublicId());
        self::assertSelectorTextContains('.form-errors', 'format JSON');
    }

    /**
     * Archive envoyée en un morceau par la page d'import, puis analysée.
     */
    private function uploaded(?string $path = null): InstagramImport
    {
        $path ??= $this->archive;
        $token = $this->uploadToken();
        $start = $this->begin($token, (int) filesize($path));
        $this->chunk($token, $start['parts'][0]['url'], 0, (string) file_get_contents($path));
        self::assertResponseIsSuccessful();
        self::getContainer()->get('doctrine')->getManager()->clear();

        return self::getContainer()->get(InstagramImportRepository::class)->findByPublicId($start['id']) ?? throw new \LogicException();
    }

    private function uploadToken(): string
    {
        $crawler = $this->client->request('GET', '/import');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-chunked-upload-token-value]')->attr('data-chunked-upload-token-value');
    }

    /**
     * @return array{id: string, show_url: string, parts: list<array{url: string, received: int}>}
     */
    private function begin(string $token, int $size): array
    {
        $this->client->request('POST', '/import/envoi', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) json_encode(['parts' => [['name' => 'instagram-camille.zip', 'size' => $size]]]));
        self::assertResponseIsSuccessful();

        /** @var array{id: string, show_url: string, parts: list<array{url: string, received: int}>} $start */
        $start = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $start;
    }

    private function chunk(string $token, string $url, int $offset, string $bytes): void
    {
        $this->client->request('POST', $url.'?debut='.$offset, server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_CSRF_TOKEN' => $token], content: $bytes);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function item(InstagramImport $import, int $timestamp): ImportItem
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        foreach (self::getContainer()->get(ImportItemRepository::class)->findBy(['import' => $import->getId()]) as $item) {
            if ($item->getTakenAt()->getTimestamp() === $timestamp) {
                return $item;
            }
        }
        throw new \LogicException('Élément introuvable.');
    }

    private function post(InstagramImport $import, int $timestamp): Post
    {
        $id = $this->item($import, $timestamp)->getTargetId();
        self::assertNotNull($id, 'La publication a été importée.');

        return self::getContainer()->get(PostRepository::class)->find($id) ?? throw new \LogicException();
    }
}
