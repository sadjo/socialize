<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Domain\Account\AccountRight;
use App\Core\Infrastructure\Doctrine\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CreateUserCommandTest extends KernelTestCase
{
    use ResetDatabase;

    public function testCreatesAnAdministratorFromTheConsole(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
        $tester->setInputs(['un-mot-de-passe-long', 'un-mot-de-passe-long']);

        $tester->execute(['email' => 'admin@exemple.test', 'username' => 'admin', '--admin' => true, '--name' => 'Administration']);

        $tester->assertCommandIsSuccessful();
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('admin@exemple.test');
        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
        self::assertTrue($user->canPublish());
        self::assertSame('Administration', $user->getAccount()->getDisplayName());
    }

    public function testGrantsThePublishingRightOnlyOnRequest(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
        // Le premier compte devient administrateur : on le crée d'abord.
        $tester->setInputs(['un-mot-de-passe-long', 'un-mot-de-passe-long']);
        $tester->execute(['email' => 'admin@exemple.test', 'username' => 'admin']);

        foreach (['lecteur' => [false, false], 'auteur' => [true, false], 'importe' => [true, true]] as $username => [$publisher, $importer]) {
            $tester->setInputs(['un-mot-de-passe-long', 'un-mot-de-passe-long']);
            $tester->execute(['email' => $username.'@exemple.test', 'username' => $username, '--publisher' => $publisher, '--instagram-import' => $importer]);
            $tester->assertCommandIsSuccessful();
            $user = self::getContainer()->get(UserRepository::class)->findOneByEmail($username.'@exemple.test');
            self::assertNotNull($user);
            self::assertSame($publisher, $user->canPublish(), $username);
            self::assertSame($importer, $user->can(AccountRight::InstagramImport), $username);
        }
    }

    public function testRejectsTwoDifferentEntries(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
        $tester->setInputs(['un-mot-de-passe-long', 'un-mot-de-passe-autre']);

        $this->expectExceptionMessageIsOrContains('pas identiques');
        $tester->execute(['email' => 'admin@exemple.test', 'username' => 'admin']);
    }

    public function testChangesAPasswordFromStandardInput(): void
    {
        $kernel = self::bootKernel();
        $register = self::getContainer()->get(\App\Core\Application\Account\RegisterAccount::class);
        $register->register('claude@exemple.test', 'claude', 'ancien-mot-de-passe');

        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, "claudeclaude\r\n");
        rewind($stdin);
        $io = new \Symfony\Component\Console\Style\SymfonyStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());
        $password = \App\Web\Command\PasswordPrompt::read($io, true, $stdin);
        self::assertSame('claudeclaude', $password, 'Le retour chariot d’un terminal web est retiré.');

        $tester = new CommandTester(new Application($kernel)->find('app:user:password'));
        $tester->setInputs(['claudeclaude', 'claudeclaude']);
        $tester->execute(['identifier' => 'claude']);
        $tester->assertCommandIsSuccessful();

        $user = self::getContainer()->get(UserRepository::class)->loadUserByIdentifier('claude');
        self::assertNotNull($user);
        self::assertTrue(password_verify('claudeclaude', $user->getPassword()));
    }

    public function testRejectsAShortPassword(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
        $tester->setInputs(['court', 'court', 'court']);

        $this->expectExceptionMessageIsOrContains('au moins 10 caractères');
        $tester->execute(['email' => 'admin@exemple.test', 'username' => 'admin']);
    }
}
