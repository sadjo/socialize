<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Media\MediaType;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Listes de publications (fils) : une seule liste plate, la suite chargée au défilement en flux Turbo
 * (infinite_list_controller), et un affichage qui ménage la mémoire graphique de Safari sur iPhone.
 */
final class FeedListTest extends AppWebTestCase
{
    private const array STREAM = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html', 'HTTP_X_SOCIALIZE_NEXT_PAGE' => '1'];

    public function testTheNextPageIsAppendedToASingleFlatList(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->publishMany($camille, 25);

        $crawler = $this->client->request('GET', '/local');
        self::assertSelectorCount(10, '.feed[data-controller="infinite-list"] > article.post', 'Publications directement dans la liste.');
        self::assertSelectorNotExists('.feed turbo-frame', 'Plus de cadres Turbo imbriqués page dans page.');
        $more = $crawler->filter('.feed > #feed-more[data-infinite-list-target="more"]:last-child a');
        self::assertCount(1, $more, 'Repère de fin, dernier élément de la liste.');

        // Deuxième page : dix publications insérées avant le repère, puis le repère de la troisième.
        $this->client->request('GET', (string) $more->attr('href'), server: self::STREAM);
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        self::assertSelectorCount(10, 'turbo-stream[action="before"][target="feed-more"] article.post');
        $third = $this->client->getCrawler()->filter('turbo-stream[action="replace"][target="feed-more"] #feed-more a')->attr('href');
        self::assertStringStartsWith('/local?apres=', (string) $third);
        self::assertSelectorNotExists('.rail', 'Seulement les publications : ni suggestions ni reste de la page.');

        // Dernière page : le repère est retiré.
        $this->client->request('GET', (string) $third, server: self::STREAM);
        self::assertSelectorCount(5, 'turbo-stream[action="before"] article.post');
        self::assertSelectorExists('turbo-stream[action="remove"][target="feed-more"]');
        self::assertSelectorNotExists('turbo-stream[action="replace"]');
    }

    /**
     * Turbo envoie ses formulaires en acceptant les flux, et le navigateur garde cet en-tête en suivant la redirection :
     * le fil ou le profil atteint ainsi doit être rendu en entier (sinon l'écran ne change pas après la connexion,
     * « Suivre » ou « Accepter »). Seule la page suivante demandée par infinite_list_controller est un flux.
     */
    public function testAFormRedirectedByTurboToAListGetsTheFullPage(): void
    {
        $camille = $this->login($this->register('camille'));
        $this->register('leo');
        $this->publishMany($camille->getAccount(), 12);
        $turbo = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

        // « Suivre » un compte suggéré, depuis l'accueil.
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/@leo/suivre"]')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: $turbo + ['HTTP_REFERER' => 'http://localhost/']);
        self::assertResponseRedirects('http://localhost/');
        $this->client->request('GET', '/', server: $turbo);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertSelectorExists('.feed-col .feed > #feed-more');
        self::assertSelectorNotExists('form[action="/@leo/suivre"]', 'Compte suivi : plus proposé.');

        // Même chose pour les autres listes, page suivante comprise (lien suivi sans JavaScript).
        $next = (string) $this->client->getCrawler()->filter('#feed-more a')->attr('href');
        foreach (['/local', '/@camille', $next] as $url) {
            $this->client->request('GET', $url, server: $turbo);
            self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8', $url);
        }
    }

    public function testWithoutJavaScriptTheLinkOpensAFullPageStartingAtTheNextPost(): void
    {
        $camille = $this->register('camille')->getAccount();
        $this->publishMany($camille, 12);

        $crawler = $this->client->request('GET', '/local');
        $this->client->request('GET', (string) $crawler->filter('#feed-more a')->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.feed-col', 'Page complète.');
        self::assertSelectorCount(2, 'article.post');
        self::assertSelectorTextContains('article.post', 'Photo 2');

        // Fil d'un profil : même chose, sans publication ouverte.
        $crawler = $this->client->request('GET', '/@camille');
        $first = $crawler->filter('.grid3 > .gt')->first()->attr('href');
        $crawler = $this->client->request('GET', (string) $first);
        $this->client->request('GET', (string) $crawler->filter('#feed-more a')->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'article.post');
        self::assertSelectorTextContains('article.post', 'Photo 1');
    }

    /**
     * Vidéos : aucun lecteur dans la page, seulement l'affiche et l'adresse de la vidéo ; video_controller crée le
     * lecteur quand la vidéo est bien visible (Safari sur iPhone laissait noires les vidéos d'un fil qui comptait plus
     * d'une centaine de lecteurs). Comme les photos, seule l'affiche du premier média est chargée avec la page.
     */
    public function testVideosAreRenderedAsPostersWithoutAPlayer(): void
    {
        $alice = Account::remote('alice', 'remote.test', 'https://remote.test/users/alice');
        $post = Post::federated($alice, 'Deux vidéos', Visibility::Public, null, 'https://remote.test/notes/1', 'https://remote.test/@alice/1', new \DateTimeImmutable());
        foreach ([1, 2] as $i) {
            MediaAttachment::remote($post, MediaType::Video, $i - 1, 'https://remote.test/media/'.$i.'.mp4', 'video/mp4', 'Vidéo '.$i, 1080, 1920, null, 'https://remote.test/media/'.$i.'.jpg');
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($alice);
        $em->persist($post);
        $em->flush();

        $this->client->request('GET', '/p/'.$post->getPublicId());
        self::assertSelectorNotExists('video', 'Aucun lecteur vidéo dans la page.');
        self::assertSelectorCount(2, '.slide.vid .player[data-controller="video"]');
        self::assertSelectorExists('.player[data-video-src-value="https://remote.test/media/1.mp4"][data-video-label-value="Vidéo 1"] img.poster[src="https://remote.test/media/1.jpg"]');
        self::assertSelectorExists('.player[data-video-src-value="https://remote.test/media/2.mp4"] img.poster[data-src="https://remote.test/media/2.jpg"]:not([src])');

        // Pas d'écran noir pendant le chargement : le lecteur, posé sur l'affiche, reste invisible jusqu'à sa première
        // image (classe « ready » ajoutée par video_controller).
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');
        self::assertStringContainsString('.player video{position:absolute;inset:0;cursor:pointer;opacity:0;transition:opacity .15s}', $css);
        self::assertStringContainsString('.player.ready video{opacity:1}', $css);
    }

    /**
     * Mémoire graphique : un filtre CSS ou un flou d'arrière-plan fait de chaque élément une couche à part ; sur les
     * photos des listes, un voile translucide remplace le filtre, et les boutons posés sur les médias n'ont plus de flou.
     */
    public function testListPhotosAreSoftenedByAVeilRatherThanACssFilter(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');

        self::assertStringNotContainsString('.slide img,article.post .media .slide video,.gt img', $css, 'Pas de filtre sur chaque photo.');
        self::assertStringContainsString('article.post .media .slide::after,.gt::after{content:"";position:absolute;inset:0;background:var(--media-veil);pointer-events:none}', $css);
        self::assertStringNotContainsString('filter:var(--media-filter)', $css, 'Ni sur les photos ni sur les vidéos.');
        foreach (['off' => 'transparent', 'medium' => 'rgb(142 142 142 / .135)', 'strong' => 'rgb(66 66 66 / .21)'] as $level => $veil) {
            self::assertStringContainsString(':root[data-media-filter="'.$level.'"]{--media-veil:'.$veil.'}', $css);
        }
        foreach (['.snd{', '.cnt{', '.nb{'] as $button) {
            $rule = substr($css, (int) strpos($css, $button), (int) strpos(substr($css, (int) strpos($css, $button)), '}'));
            self::assertStringNotContainsString('backdrop-filter', $rule, $button.' sans flou d’arrière-plan.');
        }
    }

    private function publishMany(Account $author, int $count): void
    {
        $composer = self::getContainer()->get(PostComposer::class);
        for ($i = 1; $i <= $count; ++$i) {
            $composer->publish($author, 'Photo '.$i, [['file' => $this->jpeg(40, 50), 'alt' => null]], Visibility::Public);
        }
    }
}
