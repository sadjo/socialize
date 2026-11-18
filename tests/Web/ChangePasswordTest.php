<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Tests\AppWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ChangePasswordTest extends AppWebTestCase
{
    public function testAPersonChangesItsPasswordAndStaysSignedIn(): void
    {
        $this->login($this->register('camille', password: 'ancien-mot-de-passe'));
        $crawler = $this->client->request('GET', '/reglages');
        $crawler = $this->client->click($crawler->selectLink('Mot de passe')->link());
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Changer le mot de passe')->form([
            'change_password[currentPassword]' => 'ancien-mot-de-passe',
            'change_password[newPassword][first]' => 'nouveau-mot-de-passe',
            'change_password[newPassword][second]' => 'nouveau-mot-de-passe',
        ]));
        self::assertResponseRedirects('/reglages/mot-de-passe');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Mot de passe changé');
        self::assertTrue($this->passwordIs('nouveau-mot-de-passe'));

        // Toujours connectée après le changement.
        $this->client->request('GET', '/reglages');
        self::assertResponseIsSuccessful();
    }

    public function testTheCurrentPasswordAndTheRulesAreChecked(): void
    {
        $this->login($this->register('camille', password: 'ancien-mot-de-passe'));
        $crawler = $this->client->request('GET', '/reglages/mot-de-passe');
        $form = $crawler->selectButton('Changer le mot de passe')->form();

        $this->client->submit($form, [
            'change_password[currentPassword]' => 'pas-le-bon',
            'change_password[newPassword][first]' => 'nouveau-mot-de-passe',
            'change_password[newPassword][second]' => 'nouveau-mot-de-passe',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Ce n’est pas votre mot de passe actuel.');

        $this->client->submit($form, [
            'change_password[currentPassword]' => 'ancien-mot-de-passe',
            'change_password[newPassword][first]' => 'court',
            'change_password[newPassword][second]' => 'court',
        ]);
        self::assertSelectorTextContains('form', 'au moins 10 caractères');

        $this->client->submit($form, [
            'change_password[currentPassword]' => 'ancien-mot-de-passe',
            'change_password[newPassword][first]' => 'nouveau-mot-de-passe',
            'change_password[newPassword][second]' => 'autre-mot-de-passe',
        ]);
        self::assertSelectorTextContains('form', 'ne sont pas identiques');
        self::assertTrue($this->passwordIs('ancien-mot-de-passe'), 'Rien n’a changé.');
    }

    public function testVisitorsAreSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/reglages/mot-de-passe');
        self::assertResponseRedirects('/connexion');
    }

    private function passwordIs(string $password): bool
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $user = self::getContainer()->get(UserRepository::class)->loadUserByIdentifier('camille');
        self::assertInstanceOf(User::class, $user);

        return self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $password);
    }
}
