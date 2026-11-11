<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Core\Domain\Account\Invitation;
use App\Core\Domain\Account\InvitationStatus;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\InvitationRepository;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Tests\AppWebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class InvitationTest extends AppWebTestCase
{
    use ClockSensitiveTrait;

    /** @var array<string, array{server: mixed, env: mixed}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Instance fermée, comme en production : l'invitation est la seule façon d'entrer.
        foreach (['HOME_LOGIN_ONLY' => '1', 'REGISTRATION' => 'closed'] as $name => $value) {
            $this->savedEnv[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $saved) {
            $_SERVER[$name] = $saved['server'];
            $_ENV[$name] = $saved['env'];
            if (null === $saved['server']) {
                unset($_SERVER[$name]);
            }
            if (null === $saved['env']) {
                unset($_ENV[$name]);
            }
        }
        parent::tearDown();
    }

    public function testTheAdministrationIsReservedToTheAdministrator(): void
    {
        $admin = $this->register('alice');
        $member = $this->register('hugo');

        $this->client->request('GET', '/admin/invitations');
        self::assertResponseRedirects('/connexion');

        $this->login($member);
        $this->client->request('GET', '/admin/invitations');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('nav.side a[href="/admin/invitations"]');

        $this->login($admin);
        $this->client->request('GET', '/');
        self::assertSelectorExists('nav.side a[href="/admin/invitations"]', 'Entrée « Administration » dans le menu.');
        $this->client->request('GET', '/admin/invitations');
        self::assertResponseIsSuccessful();
    }

    public function testALinkInvitationCreatesOneAccountEvenWhenRegistrationIsClosed(): void
    {
        $this->login($this->register('alice'));
        $crawler = $this->client->request('GET', '/admin/invitations');
        $this->client->submit($crawler->selectButton('Inviter')->form());
        self::assertResponseRedirects('/admin/invitations');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Lien d’invitation créé.');
        $link = (string) $this->client->getCrawler()->filter('.invite-link input')->attr('value');
        self::assertMatchesRegularExpression('#^http://localhost/invitation/[A-Za-z0-9_-]{43}$#', $link);
        self::assertSelectorTextContains('table.invitations', 'Lien à copier');
        self::assertSelectorTextContains('table.invitations', 'En attente');

        $token = substr($link, \strlen('http://localhost/invitation/'));
        $invitation = $this->onlyInvitation();
        self::assertSame(hash('sha256', $token), $this->tokenHashOf($invitation), 'Seule l’empreinte du jeton est enregistrée.');

        // Le lien n'est montré qu'une fois.
        $this->client->request('GET', '/admin/invitations');
        self::assertSelectorNotExists('.invite-link');

        // La personne invitée crée son compte, alors que /inscription est fermée.
        $this->client->request('GET', '/deconnexion');
        $this->client->request('GET', '/inscription');
        self::assertResponseRedirects('/');
        $crawler = $this->client->request('GET', '/invitation/'.$token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.note', 'Alice vous invite à rejoindre Socialize');
        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration[email]' => 'lina@exemple.fr',
            'registration[username]' => 'lina',
            'registration[password][first]' => 'mot-de-passe-solide',
            'registration[password][second]' => 'mot-de-passe-solide',
        ]));
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorExists('nav.side', 'Connectée après la création du compte.');

        $lina = self::getContainer()->get(UserRepository::class)->findOneByEmail('lina@exemple.fr');
        self::assertInstanceOf(User::class, $lina);
        self::assertFalse($lina->isAdmin());
        $invitation = $this->onlyInvitation();
        self::assertSame('lina', $invitation->getUsedBy()?->getUsername());
        self::assertSame(InvitationStatus::Used, $invitation->status(new \DateTimeImmutable()));

        // Usage unique.
        $this->client->request('GET', '/deconnexion');
        $this->client->request('GET', '/invitation/'.$token);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Invitation non valable');
    }

    public function testAnEmailInvitationIsSentAndReservedToItsAddress(): void
    {
        $this->login($this->register('alice'));
        $crawler = $this->client->request('GET', '/admin/invitations');
        $this->client->submit($crawler->selectButton('Inviter')->form(['invitation[email]' => 'Hugo@Exemple.fr']));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'hugo@exemple.fr');
        self::assertEmailHeaderSame($email, 'Subject', 'Alice vous invite sur Socialize');
        self::assertEmailTextBodyContains($email, 'http://localhost/invitation/');
        self::assertEmailHtmlBodyContains($email, 'Créer mon compte');
        self::assertResponseRedirects('/admin/invitations');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Invitation envoyée à hugo@exemple.fr.');
        self::assertSelectorTextContains('table.invitations', 'hugo@exemple.fr');

        self::assertSame(1, preg_match('#/invitation/([A-Za-z0-9_-]+)#', (string) $this->client->getCrawler()->filter('.invite-link input')->attr('value'), $m));
        $this->client->request('GET', '/deconnexion');
        $crawler = $this->client->request('GET', '/invitation/'.$m[1]);
        self::assertSelectorExists('input[name="registration[email]"][disabled][value="hugo@exemple.fr"]', 'Adresse imposée.');
        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration[username]' => 'hugo',
            'registration[password][first]' => 'mot-de-passe-solide',
            'registration[password][second]' => 'mot-de-passe-solide',
        ]));
        self::assertResponseRedirects('/');
        self::assertInstanceOf(User::class, self::getContainer()->get(UserRepository::class)->findOneByEmail('hugo@exemple.fr'));
    }

    public function testExpiredAndRevokedInvitationsAreRefused(): void
    {
        $clock = self::mockTime('2026-11-14 10:00:00');
        $this->login($this->register('alice'));
        $crawler = $this->client->request('GET', '/admin/invitations');
        $this->client->submit($crawler->selectButton('Inviter')->form());
        $this->client->followRedirect();
        $expired = substr((string) $this->client->getCrawler()->filter('.invite-link input')->attr('value'), \strlen('http://localhost/invitation/'));
        $crawler = $this->client->request('GET', '/admin/invitations');
        $this->client->submit($crawler->selectButton('Inviter')->form());
        $this->client->followRedirect();
        $revoked = substr((string) $this->client->getCrawler()->filter('.invite-link input')->attr('value'), \strlen('http://localhost/invitation/'));

        // Révocation de la plus récente.
        $this->client->submit($this->client->getCrawler()->filter('table.invitations form')->first()->form());
        self::assertResponseRedirects('/admin/invitations');
        $this->client->followRedirect();
        self::assertSelectorTextContains('table.invitations', 'Révoquée');

        $clock->sleep(8 * 24 * 3600);
        $this->client->request('GET', '/admin/invitations');
        self::assertSelectorTextContains('table.invitations', 'Expirée');

        $this->client->request('GET', '/deconnexion');
        foreach ([$expired, $revoked, 'jeton-inconnu-0123456789abcdef'] as $token) {
            $this->client->request('GET', '/invitation/'.$token);
            self::assertResponseStatusCodeSame(410, $token);
        }
    }

    private function onlyInvitation(): Invitation
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $invitations = self::getContainer()->get(InvitationRepository::class)->findAll();
        self::assertCount(1, $invitations);

        return $invitations[0];
    }

    private function tokenHashOf(Invitation $invitation): string
    {
        $hash = new \ReflectionProperty(Invitation::class, 'tokenHash')->getValue($invitation);
        self::assertIsString($hash);

        return $hash;
    }
}
