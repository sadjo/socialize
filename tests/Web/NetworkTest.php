<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Tests\AppWebTestCase;

final class NetworkTest extends AppWebTestCase
{
    public function testProfileCountersLinkToTheFollowersAndFollowingLists(): void
    {
        $camille = $this->register('camille')->getAccount();
        $hugo = $this->register('hugo')->getAccount();
        $lea = $this->register('lea')->getAccount();
        $this->social()->follow($hugo, $camille);
        $this->social()->follow($camille, $lea);

        $crawler = $this->client->request('GET', '/@camille');
        $this->client->click($crawler->filter('.stats a')->eq(0)->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.netlist', 'Hugo');
        self::assertSelectorNotExists('.netlist form', 'Un visiteur ne peut rien retirer.');

        $crawler = $this->client->request('GET', '/@camille');
        $this->client->click($crawler->filter('.stats a')->eq(1)->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.netlist', 'Lea');
        self::assertSelectorTextNotContains('.netlist', 'Hugo');
    }

    public function testListsArePagedFromTheMostRecentFollow(): void
    {
        $camille = $this->register('camille')->getAccount();
        for ($i = 1; $i <= 32; ++$i) {
            $this->social()->follow($this->register(\sprintf('abonne%02d', $i))->getAccount(), $camille);
        }

        $crawler = $this->client->request('GET', '/@camille/abonnes');
        self::assertSelectorCount(30, '.netlist .found');
        self::assertSelectorTextContains('.netlist .found', 'Abonne32');

        $next = $crawler->filter('.netlist turbo-frame[loading="lazy"]')->attr('src');
        self::assertNotNull($next);
        $this->client->request('GET', $next);
        self::assertSelectorCount(2, '.found');
        self::assertSelectorTextContains('.found', 'Abonne02');
    }

    public function testHiddenListsAreReservedToTheOwnerAndAcceptedFollowers(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $this->social()->follow($hugo->getAccount(), $camille->getAccount());

        $this->login($camille);
        $crawler = $this->client->request('GET', '/reglages');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['profile_settings[hideNetwork]' => true]));
        self::assertResponseRedirects();
        self::assertTrue($this->account('camille')->isNetworkHidden());

        $this->client->request('GET', '/@camille/abonnes');
        self::assertSelectorTextContains('.netlist', 'Hugo', 'La titulaire voit toujours ses listes.');

        $this->login($hugo);
        $this->client->request('GET', '/@camille/abonnements');
        self::assertSelectorTextContains('.empty', 'Aucun abonnement', 'Un abonné voit les listes.');

        $this->login($this->register('lea'));
        $this->client->request('GET', '/@camille');
        self::assertSelectorNotExists('.stats a', 'Les compteurs ne sont plus des liens.');
        self::assertSelectorTextContains('.stats', '1 abonné');
        $this->client->request('GET', '/@camille/abonnes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty', 'Liste masquée');
        self::assertSelectorTextNotContains('main', 'Hugo');

        $this->client->request('GET', '/@camille/abonnes?apres=x');
        self::assertSelectorNotExists('.found');
    }

    public function testTheOwnerRemovesAFollowerAndAFollowing(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo')->getAccount();
        $lea = $this->register('lea')->getAccount();
        $this->social()->follow($hugo, $camille->getAccount());
        $this->social()->follow($camille->getAccount(), $lea);

        $this->login($camille);
        $crawler = $this->client->request('GET', '/@camille/abonnes');
        $this->client->submit($crawler->selectButton('Retirer')->form());
        self::assertResponseRedirects('http://localhost/@camille/abonnes', message: 'Retour sur la liste (page d’origine).');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.empty', 'Aucun abonné');
        self::assertFalse($this->follows()->isFollowing($this->account('hugo'), $this->account('camille')));

        $crawler = $this->client->request('GET', '/@camille/abonnements');
        $this->client->submit($crawler->selectButton('Ne plus suivre')->form());
        self::assertResponseRedirects();
        self::assertFalse($this->follows()->isFollowing($this->account('camille'), $this->account('lea')));
    }

    public function testRemovingAFollowerRequiresAValidToken(): void
    {
        $camille = $this->register('camille');
        $this->social()->follow($this->register('hugo')->getAccount(), $camille->getAccount());

        $this->login($camille);
        $this->client->request('POST', '/@hugo/retirer-des-abonnes', ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->follows()->isFollowing($this->account('hugo'), $this->account('camille')));
    }

    private function social(): SocialActions
    {
        return self::getContainer()->get(SocialActions::class);
    }

    private function follows(): FollowRepository
    {
        return self::getContainer()->get(FollowRepository::class);
    }

    private function account(string $username): Account
    {
        self::getContainer()->get('doctrine')->getManager()->clear();

        return self::getContainer()->get(AccountRepository::class)->findLocal($username) ?? throw new \LogicException();
    }
}
