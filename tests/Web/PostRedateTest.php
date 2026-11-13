<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Post\Post;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Date de publication : modifiable par un administrateur seulement, en modifiant sa publication.
 */
final class PostRedateTest extends AppWebTestCase
{
    public function testAnAdministratorChangesThePublicationDate(): void
    {
        $admin = $this->register('alice');
        self::assertTrue($admin->isAdmin());
        $post = self::getContainer()->get(PostComposer::class)->publish($admin->getAccount(), 'Été', [['file' => $this->jpeg(), 'alt' => null]]);

        $this->login($admin);
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        self::assertSame('datetime-local', $crawler->filter('input[name="edit_post[publishedAt]"]')->attr('type'));

        // Jamais dans le futur.
        $form['edit_post[publishedAt]'] = '2099-01-01T10:00';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'ne peut pas être dans le futur');

        // Heure de Paris dans le formulaire (14 juillet, UTC+2).
        $form['edit_post[publishedAt]'] = '2025-07-14T20:30';
        $this->client->submit($form);
        self::assertResponseRedirects('/p/'.$post->getPublicId());
        self::assertSame('2025-07-14T18:30:00+00:00', $this->post($post)->getPublishedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function testAnAuthorWhoIsNotAdministratorCannotChangeIt(): void
    {
        $this->register('alice');
        $camille = $this->register('camille');
        $post = self::getContainer()->get(PostComposer::class)->publish($camille->getAccount(), 'Été', [['file' => $this->jpeg(), 'alt' => null]]);

        $this->login($camille);
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        self::assertCount(0, $crawler->filter('input[name="edit_post[publishedAt]"]'));
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['edit_post'] ?? null);
        $values['edit_post']['publishedAt'] = '2020-01-01T10:00';
        $this->client->request('POST', '/p/'.$post->getPublicId().'/modifier', $values);
        self::assertNotSame('2020', $this->post($post)->getPublishedAt()->format('Y'), 'Champ refusé.');
    }

    private function post(Post $post): Post
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $fresh = self::getContainer()->get(PostRepository::class)->find($post->getId());
        self::assertInstanceOf(Post::class, $fresh);

        return $fresh;
    }
}
