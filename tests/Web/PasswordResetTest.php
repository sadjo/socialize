<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\AppWebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * « Mot de passe oublié » des comptes de l'instance : lien par e-mail, valable une heure, qui ne sert qu'une fois.
 */
final class PasswordResetTest extends AppWebTestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // Limites d'envoi remises à zéro.
        self::getContainer()->get('login.cache')->clear();
    }

    public function testAForgottenPasswordIsReplacedFromTheLinkReceivedByEmail(): void
    {
        $this->register('camille', 'camille@exemple.fr', 'ancien-mot-de-passe');
        $this->client->request('GET', '/connexion');
        $this->client->clickLink('Mot de passe oublié ?');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Recevoir le lien', ['forgot_password[identifier]' => '@camille']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Regardez vos e-mails');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'camille@exemple.fr');
        self::assertEmailHtmlBodyContains($email, 'Choisir un nouveau mot de passe');
        $link = $this->linkIn($email->toString());

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card', '@camille');
        $this->client->submitForm('Changer le mot de passe', [
            'reset_password[newPassword][first]' => 'nouveau-mot-de-passe',
            'reset_password[newPassword][second]' => 'nouveau-mot-de-passe',
        ]);
        self::assertResponseRedirects('/connexion?onglet=socialize');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'Mot de passe changé');

        // Le lien ne sert qu'une fois.
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Lien expiré ou déjà utilisé');

        // Seul le nouveau mot de passe ouvre le compte.
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifier' => 'camille', 'password' => 'ancien-mot-de-passe']));
        $this->client->followRedirect();
        self::assertSelectorExists('#auth-panel-socialize .form-errors');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifier' => 'camille', 'password' => 'nouveau-mot-de-passe']));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('nav.side');
    }

    public function testAnUnknownAccountGetsTheSameAnswerWithoutEmail(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['forgot_password[identifier]' => 'personne@exemple.fr']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Regardez vos e-mails');
        self::assertEmailCount(0);
    }

    public function testAnExpiredOrAlteredLinkIsRefused(): void
    {
        $clock = self::mockTime('2026-11-20 10:00:00');
        $this->register('camille', 'camille@exemple.fr');
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['forgot_password[identifier]' => 'camille@exemple.fr']);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        $link = $this->linkIn($email->toString());

        $this->client->request('GET', preg_replace('/empreinte=[0-9a-f]/', 'empreinte=z', $link) ?? '');
        self::assertResponseStatusCodeSame(404, 'Lien altéré.');

        $clock->sleep(3601);
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(404, 'Lien expiré au bout d’une heure.');
    }

    public function testRequestsForTheSameAccountAreLimited(): void
    {
        $this->register('camille', 'camille@exemple.fr');
        for ($i = 0; $i < 4; ++$i) {
            $this->client->request('GET', '/mot-de-passe-oublie');
            $this->client->submitForm('Recevoir le lien', ['forgot_password[identifier]' => 'camille']);
            self::assertResponseIsSuccessful();
            self::assertEmailCount($i < 3 ? 1 : 0, message: '3 e-mails par heure au plus pour un compte.');
        }
    }

    public function testAnInvalidMailerDsnDoesNotBreakThePage(): void
    {
        $saved = ['server' => $_SERVER['MAILER_DSN'] ?? null, 'env' => $_ENV['MAILER_DSN'] ?? null];
        $_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = 'smtp.exemple.fr:587';
        try {
            $this->register('camille', 'camille@exemple.fr');
            $this->client->request('GET', '/mot-de-passe-oublie');
            self::assertResponseIsSuccessful();
            $this->client->submitForm('Recevoir le lien', ['forgot_password[identifier]' => 'camille']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Regardez vos e-mails');
        } finally {
            if (null === $saved['server']) {
                unset($_SERVER['MAILER_DSN']);
            } else {
                $_SERVER['MAILER_DSN'] = $saved['server'];
            }
            if (null === $saved['env']) {
                unset($_ENV['MAILER_DSN']);
            } else {
                $_ENV['MAILER_DSN'] = $saved['env'];
            }
        }
    }

    private function linkIn(string $email): string
    {
        self::assertSame(1, preg_match('#http://localhost/mot-de-passe/nouveau\?[^\s"<]+#', quoted_printable_decode($email), $m));

        return html_entity_decode($m[0]);
    }
}
