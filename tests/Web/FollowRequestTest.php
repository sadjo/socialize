<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Notification\NotificationType;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\NotificationRepository;
use App\Tests\AppWebTestCase;

final class FollowRequestTest extends AppWebTestCase
{
    public function testAnAccountApprovesOrRefusesItsFollowRequests(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $lea = $this->register('lea');

        // Réglage activé dans le profil.
        $this->login($camille);
        $crawler = $this->client->request('GET', '/reglages');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['profile_settings[approveFollowers]' => true]));
        self::assertResponseRedirects();
        self::assertTrue($this->account('camille')->manuallyApprovesFollowers());

        // Hugo et Léa demandent à suivre Camille : en attente.
        foreach (['hugo' => $hugo, 'lea' => $lea] as $user) {
            $this->login($user);
            $crawler = $this->client->request('GET', '/@camille');
            $this->client->submit($crawler->selectButton('Suivre')->form());
            $this->client->request('GET', '/@camille');
            self::assertSelectorTextContains('.btn.done', 'Demande envoyée');
        }
        self::assertFalse($this->follows()->isFollowing($this->account('hugo'), $this->account('camille')));
        self::assertSame(0, $this->follows()->countFollowers($this->account('camille')));
        self::assertCount(2, $this->requestNotifications());

        // Camille est prévenue, puis accepte Hugo et refuse Léa.
        $this->login($camille);
        $this->client->request('GET', '/notifications');
        self::assertSelectorTextContains('.notifs', 'demande à s’abonner à votre compte');
        $crawler = $this->client->request('GET', '/@camille');
        self::assertSelectorTextContains('.stats', '2 demandes');
        $crawler = $this->client->click($crawler->filter('.stats a')->last()->link());
        self::assertSelectorCount(2, '.netlist .found');
        $this->client->submit($crawler->filter('#follow-request-hugo')->selectButton('Accepter')->form());
        self::assertResponseRedirects('/abonnes/demandes');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash, .toast, body', 'Hugo fait maintenant partie de vos abonnés');
        $this->client->submit($crawler->filter('#follow-request-lea')->selectButton('Refuser')->form());
        self::assertResponseRedirects();

        self::assertTrue($this->follows()->isFollowing($this->account('hugo'), $this->account('camille')));
        self::assertNull($this->follows()->findBetween($this->account('lea'), $this->account('camille')), 'Demande refusée : supprimée.');
        self::assertSame([], $this->requestNotifications(), 'Les demandes traitées n’ont plus de notification.');
        $this->client->request('GET', '/abonnes/demandes');
        self::assertSelectorTextContains('.empty', 'Aucune demande en attente');
    }

    public function testDisablingTheSettingAcceptsPendingRequests(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $camille->getAccount()->requireFollowApproval(true);
        self::getContainer()->get('doctrine')->getManager()->flush();

        $this->login($hugo);
        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Suivre')->form());
        self::assertFalse($this->follows()->isFollowing($this->account('hugo'), $this->account('camille')));

        $this->login($camille);
        $crawler = $this->client->request('GET', '/reglages');
        self::assertSelectorTextContains('main', '1 demande en attente');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['profile_settings[approveFollowers]' => false]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '1 demande d’abonnement acceptée');
        self::assertTrue($this->follows()->isFollowing($this->account('hugo'), $this->account('camille')));
    }

    public function testCancellingARequestRemovesItsNotification(): void
    {
        $camille = $this->register('camille');
        $hugo = $this->register('hugo');
        $camille->getAccount()->requireFollowApproval(true);
        self::getContainer()->get('doctrine')->getManager()->flush();

        $this->login($hugo);
        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Suivre')->form());
        self::assertCount(1, $this->requestNotifications());
        $crawler = $this->client->request('GET', '/@camille');
        $this->client->submit($crawler->selectButton('Demande envoyée · Annuler')->form());

        self::assertSame([], $this->requestNotifications());
        self::assertNull($this->follows()->findBetween($this->account('hugo'), $this->account('camille')));
    }

    public function testOnlyTheOwnerCanAcceptAndWithAValidToken(): void
    {
        $this->register('camille');
        $this->login($this->register('hugo'));
        $this->client->request('POST', '/@camille/accepter-l-abonnement', ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/abonnes/demandes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty', 'Aucune demande en attente');
    }

    /**
     * @return list<object>
     */
    private function requestNotifications(): array
    {
        self::getContainer()->get('doctrine')->getManager()->clear();

        return self::getContainer()->get(NotificationRepository::class)->findBy(['type' => NotificationType::FollowRequest]);
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
