<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Publications texte (sans photo ni vidéo) : dans le fil, sur la page de la publication, et dans l'onglet « Textes »
 * du profil, à côté de l'onglet « Photos ».
 */
final class TextPostTest extends AppWebTestCase
{
    public function testATextPostShowsItsTextInPlaceOfTheMedia(): void
    {
        $camille = $this->register('camille')->getAccount();
        $post = $this->text($camille, "Une première ligne\net une seconde, avec https://example.org/page");

        // Fil : le texte à la place des médias, sans être répété en légende.
        $this->client->request('GET', '/local');
        self::assertSelectorExists('#post-'.$post->getPublicId().' .ptext[data-controller="text-clamp"] .ptext-body');
        self::assertSelectorTextContains('#post-'.$post->getPublicId().' .ptext-body', 'Une première ligne');
        self::assertSelectorExists('#post-'.$post->getPublicId().' .ptext-body br', 'Retours à la ligne conservés.');
        self::assertSelectorExists('#post-'.$post->getPublicId().' .ptext-body a.link[href="https://example.org/page"]');
        self::assertSelectorNotExists('#post-'.$post->getPublicId().' .media');
        self::assertSelectorNotExists('#post-'.$post->getPublicId().' .pbody .ptext-body');
        self::assertSelectorTextNotContains('#post-'.$post->getPublicId().' .pbody', 'Une première ligne');

        // Page de la publication : le texte en entier, sans coupure.
        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pd-media.pd-text .ptext.full .ptext-body');
        self::assertSelectorNotExists('.pd-text [data-controller="text-clamp"]');
        self::assertSelectorNotExists('.cmts .cmt', 'Le texte n’est pas répété en premier commentaire.');
    }

    public function testATextPostWithAContentWarningWaitsToBeShown(): void
    {
        $camille = $this->register('camille')->getAccount();
        $post = $this->text($camille, 'Fin de la série, attention', 'Spoiler');

        $this->client->request('GET', '/local');
        self::assertSelectorTextContains('#post-'.$post->getPublicId().' details.ptext-cw summary', 'Spoiler');
        self::assertSelectorExists('#post-'.$post->getPublicId().' details.ptext-cw:not([open]) .ptext-body');
    }

    /**
     * Profil : onglets « Photos » et « Textes » avec leur nombre ; sans onglet dans l'adresse, celui qui a le plus de
     * publications est ouvert. L'onglet « Textes » se charge au défilement, comme la grille.
     */
    public function testTheProfileHasAPhotosTabAndATextsTab(): void
    {
        $camille = $this->register('camille')->getAccount();
        self::getContainer()->get(PostComposer::class)->publish($camille, 'Lac', [['file' => $this->jpeg(40, 50), 'alt' => null]]);
        for ($i = 1; $i <= 26; ++$i) {
            $this->text($camille, 'Texte '.$i);
        }

        $crawler = $this->client->request('GET', '/@camille');
        self::assertSelectorExists('.ptabs a.on[href="/@camille/textes"][aria-current="page"]', 'Plus de textes que de photos : onglet Textes.');
        self::assertSelectorTextContains('.ptabs a[href="/@camille/photos"] .n', '1');
        self::assertSelectorTextContains('.ptabs a[href="/@camille/textes"] .n', '26');
        self::assertSelectorTextContains('.stats', '27 publications');
        self::assertCount(24, $crawler->filter('.ptexts[data-controller="infinite-list"] > a.tcard'));
        self::assertSelectorNotExists('.grid3');

        // Suite de l'onglet Textes, en flux Turbo.
        $next = (string) $crawler->filter('.ptexts > #texts-more a')->attr('href');
        self::assertStringStartsWith('/@camille/textes?apres=', $next);
        $this->client->request('GET', $next, server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html', 'HTTP_X_SOCIALIZE_NEXT_PAGE' => '1']);
        self::assertSelectorCount(2, 'turbo-stream[action="before"][target="texts-more"] a.tcard');

        // Onglet Photos : seulement la publication avec média.
        $crawler = $this->client->request('GET', '/@camille/photos');
        self::assertSelectorExists('.ptabs a.on[href="/@camille/photos"]');
        self::assertCount(1, $crawler->filter('.grid3 > a.gt'));
        self::assertSelectorNotExists('.gt-text');
    }

    public function testAnEmptyTabSaysSo(): void
    {
        $camille = $this->login($this->register('camille'))->getAccount();
        $this->text($camille, 'Seulement du texte');

        $this->client->request('GET', '/@camille/photos');
        self::assertSelectorTextContains('div.empty', 'Aucune photo ni vidéo pour l’instant');
    }

    private function text(Account $author, string $text, ?string $contentWarning = null): Post
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $author = $em->find(Account::class, $author->getId()) ?? $author;
        $post = new Post($author, $text, Visibility::Public, $contentWarning);
        $em->persist($post);
        $em->flush();

        return $post;
    }
}
