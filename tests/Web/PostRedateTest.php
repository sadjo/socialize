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

    /**
     * Le champ ne garde que la minute : une date laissée telle quelle n'est pas un changement (sinon chaque
     * modification retirait la publication du fédivers puis l'y renvoyait, en tête des profils sur Mastodon).
     */
    public function testEditingWithoutTouchingTheDateKeepsIt(): void
    {
        $admin = $this->register('alice');
        $post = self::getContainer()->get(PostComposer::class)->publish($admin->getAccount(), 'Été', [['file' => $this->jpeg(), 'alt' => null]]);
        // Date avec des secondes, comme toute publication.
        $this->post($post)->redate(new \DateTimeImmutable('2025-09-05 14:22:37 UTC'));
        self::getContainer()->get('doctrine')->getManager()->flush();

        $this->login($admin);
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        self::assertSame('2025-09-05T16:22', $crawler->filter('input[name="edit_post[publishedAt]"]')->attr('value'), 'Heure de Paris, à la minute.');
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $form['edit_post[caption]'] = 'Été, légende retouchée';
        $this->client->submit($form);
        self::assertResponseRedirects('/p/'.$post->getPublicId());
        self::assertSame('2025-09-05T14:22:37+00:00', $this->post($post)->getPublishedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM), 'Date gardée, secondes comprises.');
        self::assertSame(1, $this->post($post)->getApRevision(), 'Simple mise à jour : ni retrait ni renvoi dans le fédivers (même identifiant).');

        // Une autre date : là, la publication est renvoyée sous un nouvel identifiant.
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $form['edit_post[publishedAt]'] = '2025-09-05T16:30';
        $this->client->submit($form);
        self::assertSame(2, $this->post($post)->getApRevision());
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
