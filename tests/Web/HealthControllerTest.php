<?php

declare(strict_types=1);

namespace App\Tests\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReportsADatabaseConnection(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('ok', $payload['status']);
        self::assertIsArray($payload['database']);
        self::assertTrue($payload['database']['reachable']);
        self::assertSame(\PHP_VERSION, $payload['php']);
    }
}
