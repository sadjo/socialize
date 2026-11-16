<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Import\Instagram\Domain\ImportItem;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Domain\ItemStatus;
use App\Import\Instagram\Infrastructure\Doctrine\ImportItemRepository;
use App\Import\Instagram\Infrastructure\Doctrine\InstagramImportRepository;
use App\Tests\AppWebTestCase;
use App\Tests\Federation\FakeFediverse;
use App\Tests\Import\InstagramArchive;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import d'une archive Instagram vers un compte Mastodon connecté : permis par un administrateur, publications
 * envoyées sur son serveur (sans copie ici), une à la fois, à une cadence qui respecte ses limites.
 */
final class MastodonImportTest extends AppWebTestCase
{
    use ConnectsWithMastodon;

    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMastodon::reset();
        FakeFediverse::$outbox = [];
        $this->archive = InstagramArchive::build(fn (): UploadedFile => $this->jpeg(400, 500));
    }

    protected function tearDown(): void
    {
        @unlink($this->archive);
        parent::tearDown();
    }

    public function testTheArchiveIsPublishedOnTheMastodonAccount(): void
    {
        $this->connect();
        $this->client->request('GET', '/import');
        self::assertResponseStatusCodeSame(403, 'Sans le droit donné par un administrateur, pas d’import.');

        $this->allowImport();
        $this->client->request('GET', '/');
        self::assertSelectorExists('.side a[href="/import"]');

        // Un média par message sur ce serveur : le carrousel de l'été part en fil de deux messages.
        FakeMastodon::$maxMedia = 1;
        FakeMastodon::$rateLimitOnce = true;
        $import = $this->uploaded();
        $crawler = $this->client->request('GET', '/import/'.$import->getPublicId());
        self::assertAnySelectorTextContains('.note', 'Sur votre compte remote.test');
        self::assertSelectorNotExists('input[name="import_options[keepOriginalDates]"][type="checkbox"]');
        $winter = $this->item($import, InstagramArchive::WINTER);
        $spring = $this->item($import, InstagramArchive::SPRING);
        $this->client->submit($crawler->selectButton('Lancer l’import')->form([
            'import_options[placement]' => 'public_quiet',
            'choix['.$winter->getPublicId().']' => 'skip',
            'choix['.$spring->getPublicId().']' => 'followers',
        ]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('h2', 'Import terminé');
        self::assertSelectorTextContains('.log', 'Envoi vers remote.test cadencé');
        self::assertSelectorTextContains('.log', 'pause : remote.test limite le nombre d’envois');
        self::assertSelectorTextContains('.log', 'publiée sur remote.test');

        // Aucune publication ici : tout est parti sur son serveur.
        self::assertSame([], self::getContainer()->get(PostRepository::class)->findBy(['account' => $this->mastodonUser()->getAccount()]));
        /** @var list<array{status?: string, visibility?: string, in_reply_to_id?: string}> $statuses */
        $statuses = array_column(\array_slice(FakeMastodon::requestsTo('POST', '/api/v1/statuses'), 1), 'body');
        self::assertCount(4, $statuses, 'Printemps, publication archivée, puis le carrousel de l’été en deux messages.');
        self::assertStringEndsWith('Publiée sur Instagram le 01/03/2016', $statuses[0]['status'] ?? '');
        self::assertSame('private', $statuses[0]['visibility'] ?? null, 'Abonnés seulement.');
        self::assertSame('direct', $statuses[1]['visibility'] ?? null, 'Archivée : visible par soi seul.');
        self::assertStringContainsString('Été à Biarritz', $statuses[2]['status'] ?? '');
        self::assertSame('unlisted', $statuses[2]['visibility'] ?? null, 'Public, sans l’annoncer : non listé.');
        self::assertSame('(2/2)', $statuses[3]['status'] ?? null);
        self::assertSame(FakeMastodon::idOf('https://remote.test/users/alice/statuses/103'), $statuses[3]['in_reply_to_id'] ?? null, 'La suite répond au premier message.');

        self::assertSame(ItemStatus::Imported, $this->item($import, InstagramArchive::SUMMER)->getStatus());
        self::assertSame(ItemStatus::Skipped, $this->item($import, InstagramArchive::STORY)->getStatus(), 'Pas de story sur Mastodon.');
        self::assertSame(ItemStatus::Skipped, $this->item($import, InstagramArchive::WINTER)->getStatus());
    }

    public function testAnAdministratorAllowsTheImport(): void
    {
        $this->connect();
        $user = $this->mastodonUser();
        $this->client->request('GET', '/deconnexion');

        $admin = $this->register('admin');
        $admin->promoteToAdmin();
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->login($admin);
        $crawler = $this->client->request('GET', '/admin/comptes');
        self::assertSelectorTextContains('section h2:contains("Comptes Mastodon")', 'Comptes Mastodon (1)');
        $this->client->submit($crawler->filter('form[action="/admin/comptes-mastodon/'.$user->getId()->toRfc4122().'/import-instagram"] button')->form());
        self::assertTrue($this->mastodonUser()->canImportInstagram());
    }

    private function allowImport(): void
    {
        $user = $this->mastodonUser();
        $user->allowInstagramImport(true);
        self::getContainer()->get('doctrine')->getManager()->flush();
        // Nouveaux droits : nouvelle session.
        $this->connect();
    }

    private function uploaded(): InstagramImport
    {
        $crawler = $this->client->request('GET', '/import');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('[data-chunked-upload-token-value]')->attr('data-chunked-upload-token-value');
        $this->client->request('POST', '/import/envoi', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) json_encode(['parts' => [['name' => 'instagram-alice.zip', 'size' => filesize($this->archive)]]]));
        self::assertResponseIsSuccessful();
        /** @var array{id: string, parts: list<array{url: string}>} $start */
        $start = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->client->request('POST', $start['parts'][0]['url'].'?debut=0', server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) file_get_contents($this->archive));
        self::assertResponseIsSuccessful();
        self::getContainer()->get('doctrine')->getManager()->clear();

        return self::getContainer()->get(InstagramImportRepository::class)->findByPublicId($start['id']) ?? throw new \LogicException();
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
}
