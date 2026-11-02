<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Application\System\SystemStatus;
use PHPUnit\Framework\TestCase;

final class SystemStatusTest extends TestCase
{
    public function testAnUnreachableDatabaseMakesTheInstanceDegraded(): void
    {
        $status = new SystemStatus('8.5.0', '8.1.0', 'SQLite', false, true);

        self::assertFalse($status->isHealthy());
        self::assertSame('degraded', $status->toArray()['status']);
    }

    public function testAReachableDatabaseMakesTheInstanceHealthy(): void
    {
        $status = new SystemStatus('8.5.0', '8.1.0', 'MariaDB', true, false);

        self::assertTrue($status->isHealthy());
        self::assertSame(['platform' => 'MariaDB', 'reachable' => true], $status->toArray()['database']);
    }
}
