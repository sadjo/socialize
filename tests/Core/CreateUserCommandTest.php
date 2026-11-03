<?php

declare(strict_types=1);

namespace App\Tests\Core;

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
        $tester->setInputs(['un-mot-de-passe-long']);

        $tester->execute(['email' => 'admin@exemple.test', 'username' => 'admin', '--admin' => true, '--name' => 'Administration']);

        $tester->assertCommandIsSuccessful();
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('admin@exemple.test');
        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
        self::assertSame('Administration', $user->getAccount()->getDisplayName());
    }

    public function testRejectsAShortPassword(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
        $tester->setInputs(['court', 'court', 'court']);

        $this->expectExceptionMessageIsOrContains('au moins 10 caractères');
        $tester->execute(['email' => 'admin@exemple.test', 'username' => 'admin']);
    }
}
