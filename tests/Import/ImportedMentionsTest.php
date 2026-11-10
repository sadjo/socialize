<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Tests\AppWebTestCase;

final class ImportedMentionsTest extends AppWebTestCase
{
    public function testMentionsOfAnImportedPostLeadToInstagramAndThoseOfALocalPostStayLocal(): void
    {
        $account = $this->register('camille')->getAccount();
        $imported = $this->post(Post::imported($account, 'Lac blanc avec @hugo.martin #montagne', Visibility::Public, new \DateTimeImmutable('2019-07-14'), new \DateTimeImmutable('2019-07-14'), false));
        $local = $this->post(new Post($account, 'Retour au lac avec @hugo'));

        $this->client->request('GET', '/p/'.$imported->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.cmt a.tag[href="https://www.instagram.com/hugo.martin/"][target="_blank"]');

        $this->client->request('GET', '/@camille/publications?depuis='.$local->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('#post-%s a.tag[href="https://www.instagram.com/hugo.martin/"]', $imported->getPublicId()));
        self::assertSelectorExists(\sprintf('#post-%s a.tag[href="/@hugo"]', $local->getPublicId()));

        $this->client->request('GET', '/p/'.$imported->getPublicId(), server: ['HTTP_ACCEPT' => 'application/activity+json']);
        /** @var array{content: string, tag: list<array{type: string}>} $note */
        $note = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('href="https://www.instagram.com/hugo.martin/" class="u-url mention">@<span>hugo.martin</span></a>', $note['content']);
        self::assertSame(['Hashtag'], array_column($note['tag'], 'type'), 'Aucune mention ActivityPub vers un compte Instagram.');
    }

    private function post(Post $post): Post
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $media = new MediaAttachment($post, MediaType::Image, 0, 'a.jpg', 'image/jpeg', 1);
        $media->markReady(800, 1000, [MediaAttachment::DERIVATIVE_LARGE => 'a.webp', MediaAttachment::DERIVATIVE_THUMB => 'a-t.webp'], []);
        $em->persist($post);
        $em->flush();

        return $post;
    }
}
