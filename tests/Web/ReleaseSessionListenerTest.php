<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Web\Http\ReleaseSession;
use App\Web\Http\ReleaseSessionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ReleaseSessionListenerTest extends TestCase
{
    public function testAMarkedActionReleasesTheSessionBeforeRunning(): void
    {
        self::assertFalse($this->sessionAfter(new ReleaseSessionFixture()->marked(...))->isStarted(), 'Session libérée : les requêtes suivantes n’attendent plus.');
    }

    public function testOtherActionsKeepTheirSession(): void
    {
        self::assertTrue($this->sessionAfter(new ReleaseSessionFixture()->unmarked(...))->isStarted());
    }

    private function sessionAfter(callable $controller): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $kernel = $this->createStub(HttpKernelInterface::class);
        new ReleaseSessionListener($this->createStub(Security::class))(new ControllerArgumentsEvent($kernel, $controller, [], $request, HttpKernelInterface::MAIN_REQUEST));

        return $session;
    }
}

/**
 * Actions d'essai : l'une marquée #[ReleaseSession], l'autre non.
 */
final class ReleaseSessionFixture
{
    #[ReleaseSession]
    public function marked(): string
    {
        return 'vignette';
    }

    public function unmarked(): string
    {
        return 'formulaire';
    }
}
