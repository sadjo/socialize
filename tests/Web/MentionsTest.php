<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Media\Application\PostComposer;
use App\Tests\AppWebTestCase;

/**
 * Mentions dans une légende : « @nom » (ici), « @nom@instance » (fédivers), « ig:@nom » (Instagram) ; suggestions
 * pendant la saisie, et notification des comptes d'ici mentionnés.
 */
final class MentionsTest extends AppWebTestCase
{
    public function testTheThreeKindsOfMentionsAreLinked(): void
    {
        $camille = $this->register('camille');
        $this->register('hugo');
        $this->publish($camille->getAccount(), 'Merci @hugo, @alice@remote.test et ig:@natgeo !');

        $this->login($camille);
        $this->client->request('GET', '/@camille');
        $crawler = $this->client->request('GET', '/local');
        self::assertSelectorExists('.post a.tag[href="/@hugo"]');
        self::assertSelectorExists('.post a.tag[href="/@alice@remote.test"]');
        $instagram = $crawler->filter('.post a.tag.ig');
        self::assertSame('https://www.instagram.com/natgeo/', $instagram->attr('href'));
        self::assertSame('@natgeo', $instagram->text());
        self::assertStringNotContainsString('ig:', $crawler->filter('.post')->first()->text());
    }

    public function testMentionedLocalAccountsAreNotifiedWhenTheyCanSeeThePost(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $lina = $this->register('lina');
        $this->publish($camille->getAccount(), 'Sortie photo avec @hugo et @camille');
        $this->publish($camille->getAccount(), 'Brouillon pour @lina', Visibility::Private);

        $this->login($hugo);
        $this->client->request('GET', '/notifications');
        self::assertSelectorTextContains('.notif.unread', 'vous a mentionné dans sa publication : « Sortie photo avec @hugo et @camille »');
        self::assertSelectorExists('.notif .notif-kind.mention');

        $this->login($camille);
        $this->client->request('GET', '/notifications');
        self::assertSelectorNotExists('.notif', 'Pas de notification pour se mentionner soi-même.');

        $this->login($lina);
        $this->client->request('GET', '/notifications');
        self::assertSelectorNotExists('.notif', 'Pas de notification pour une publication qu’on ne peut pas voir.');
    }

    public function testAMentionAddedByEditingIsNotifiedOnce(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $post = $this->publish($camille->getAccount(), 'Sortie photo');

        $this->login($camille);
        foreach (['Sortie photo avec @hugo', 'Sortie photo avec @hugo !'] as $caption) {
            $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
            $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(['edit_post[caption]' => $caption]));
            self::assertResponseRedirects();
        }

        $this->login($hugo);
        $this->client->request('GET', '/notifications');
        self::assertSelectorCount(1, '.notif');
    }

    public function testSuggestionsWhileTyping(): void
    {
        $camille = $this->register('camille');
        $this->register('hugo', 'hugo@exemple.test');
        $this->register('hubert', 'hubert@exemple.test');
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist(Account::remote('huguette', 'pixelfed.test', 'https://pixelfed.test/users/huguette'));
        $em->flush();

        $this->client->request('GET', '/mentions/suggestions?q=hu');
        self::assertResponseRedirects('/connexion', message: 'Réservé aux comptes connectés.');

        $this->login($camille);
        $crawler = $this->client->request('GET', '/publier');
        self::assertSame('mention', $crawler->filter('#compose_post_caption')->attr('data-controller'));
        self::assertSame('/mentions/suggestions', $crawler->filter('#compose_post_caption')->attr('data-mention-url-value'));

        self::assertSame(['@hubert', '@hugo', '@huguette@pixelfed.test'], $this->mentions('/mentions/suggestions?q=hu'), 'Comptes d’ici d’abord.');
        self::assertSame(['@huguette@pixelfed.test'], $this->mentions('/mentions/suggestions?q=hu@pix'));
        self::assertSame(['@hugo@localhost'], $this->mentions('/mentions/suggestions?q=hugo&full=1'), 'Adresse complète pour un envoi vers Mastodon.');
        self::assertSame([], $this->mentions('/mentions/suggestions?q=%3Cscript'));
    }

    public function testTheSecondAtSignSwitchesToFediverseAccounts(): void
    {
        $camille = $this->register('camille');
        $this->register('hugo', 'hugo@exemple.test');
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist(Account::remote('hugo', 'pixelfed.test', 'https://pixelfed.test/users/hugo'));
        $em->persist(Account::remote('huguette', 'mastodon.test', 'https://mastodon.test/users/huguette'));
        $em->flush();
        $this->login($camille);

        self::assertSame(['@hugo', '@hugo@pixelfed.test', '@huguette@mastodon.test'], $this->mentions('/mentions/suggestions?q=hu'));
        self::assertSame(['@hugo@pixelfed.test', '@huguette@mastodon.test'], $this->mentions('/mentions/suggestions?q=hu@'), 'Second « @ » : comptes du fédivers seulement.');
        self::assertSame(['@huguette@mastodon.test'], $this->mentions('/mentions/suggestions?q=hu@mast'));
        self::assertSame(['@hugo@localhost'], $this->mentions('/mentions/suggestions?q=hugo@localhost&full=1'), 'Le domaine de l’instance mène au compte d’ici.');

        // Adresse complète encore inconnue : cherchée sur son serveur (WebFinger), puis proposée.
        self::assertSame(['@alice@remote.test'], $this->mentions('/mentions/suggestions?q=alice@remote.test'));
        self::assertSame([], $this->mentions('/mentions/suggestions?q=personne@inconnu.test'));
    }

    public function testSuggestionsAsSoonAsTheAtSign(): void
    {
        $camille = $this->register('camille');
        $this->register('hugo', 'hugo@exemple.test');
        $lina = $this->register('lina', 'lina@exemple.test');
        $em = self::getContainer()->get('doctrine')->getManager();
        $alice = Account::remote('alice', 'remote.test', 'https://remote.test/users/alice');
        $em->persist($alice);
        $em->flush();
        $social = self::getContainer()->get(SocialActions::class);
        $social->follow($camille->getAccount(), $lina->getAccount());

        $this->login($camille);
        $suggestions = $this->mentions('/mentions/suggestions?q=');
        self::assertSame('@lina', $suggestions[0] ?? null, 'Comptes suivis d’abord.');
        self::assertContains('@hugo', $suggestions, 'Puis d’autres comptes d’ici.');
        self::assertNotContains('@camille', $suggestions, 'Jamais soi-même.');
        self::assertNotContains('@alice@remote.test', $suggestions, 'Pas de compte du fédivers non suivi.');
    }

    /**
     * @return list<string>
     */
    private function mentions(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return array_values(array_map(static fn (mixed $s): string => \is_array($s) && \is_string($s['mention'] ?? null) ? $s['mention'] : '', $data));
    }

    private function publish(Account $author, string $caption, Visibility $visibility = Visibility::Public): Post
    {
        return self::getContainer()->get(PostComposer::class)->publish($author, $caption, [['file' => $this->jpeg(200, 250), 'alt' => null]], $visibility);
    }
}
