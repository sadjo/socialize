<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

final class ArchiveTest extends AppWebTestCase
{
    public function testAnArchivedPostIsOnlyVisibleToItsAuthorInTheArchives(): void
    {
        $camille = $this->register('camille');
        $post = $this->publish($camille->getAccount(), 'Souvenir à ranger', Visibility::Followers);
        $this->publish($camille->getAccount(), 'Toujours là', Visibility::Public);
        $hugo = $this->register('hugo');
        $this->login($hugo);
        $this->client->submit($this->client->request('GET', '/@camille')->selectButton('Suivre')->form());

        $this->login($camille);
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->selectButton('Archiver')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.chip', 'Archivée');
        self::assertTrue($this->reload($post)->isArchived());
        self::assertSame(Visibility::Followers, $this->reload($post)->getVisibility(), 'La visibilité d’origine est conservée.');

        $this->client->request('GET', '/@camille');
        self::assertSelectorTextContains('.stats', '1 publication');
        self::assertSelectorCount(1, '.grid3 .gt');
        $this->client->click($this->client->getCrawler()->selectLink('Archives')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.grid3 .gt');
        self::assertSelectorExists('.grid3 a[href="/p/'.$post->getPublicId().'"]');

        $this->login($hugo);
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertResponseStatusCodeSame(404, 'Même un abonné ne voit plus une publication archivée.');
        $this->client->request('GET', '/');
        self::assertSelectorTextNotContains('main', 'Souvenir à ranger');
        $this->client->request('GET', '/@camille');
        self::assertSelectorNotExists('.ptabs a[href$="/archives"]');
        $this->client->request('GET', '/@camille/archives');
        self::assertResponseStatusCodeSame(404);

        $this->login($camille);
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId());
        $this->client->submit($crawler->selectButton('Désarchiver')->form());
        self::assertFalse($this->reload($post)->isArchived());
        $this->login($hugo);
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertResponseIsSuccessful('Désarchivée, elle retrouve sa visibilité « Abonnés ».');
    }

    public function testOnlyTheAuthorArchivesWithAValidToken(): void
    {
        $camille = $this->register('camille');
        $post = $this->publish($camille->getAccount(), 'À moi', Visibility::Public);

        $this->login($this->register('hugo'));
        $this->client->request('POST', '/p/'.$post->getPublicId().'/archiver', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->login($camille);
        $this->client->request('POST', '/p/'.$post->getPublicId().'/archiver', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($post)->isArchived());
    }

    private function publish(Account $author, string $caption, Visibility $visibility): Post
    {
        return self::getContainer()->get(PostComposer::class)->publish($author, $caption, [['file' => $this->jpeg(200, 250), 'alt' => null]], $visibility);
    }

    private function reload(Post $post): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();

        return self::getContainer()->get(PostRepository::class)->findByPublicId($post->getPublicId()) ?? throw new \LogicException();
    }
}
