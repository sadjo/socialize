<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Application\Feed\Cursor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CursorTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $cursor = new Cursor(new \DateTimeImmutable('2026-11-12 10:00:00'), Uuid::v7());

        $parsed = Cursor::fromString($cursor->toString());

        self::assertNotNull($parsed);
        self::assertEquals($cursor->publishedAt, $parsed->publishedAt);
        self::assertTrue($cursor->id->equals($parsed->id));
    }

    public function testInvalidValuesAreIgnored(): void
    {
        self::assertNull(Cursor::fromString(null));
        self::assertNull(Cursor::fromString('nimporte-quoi'));
        self::assertNull(Cursor::fromString('1.0_'.str_repeat('0', 22)));
    }
}
