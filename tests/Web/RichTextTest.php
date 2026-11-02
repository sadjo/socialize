<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Media\Application\MediaUrls;
use App\Web\Twig\SocializeExtension;
use PHPUnit\Framework\TestCase;

final class RichTextTest extends TestCase
{
    public function testTextIsEscapedAndHashtagsAndMentionsAreHighlighted(): void
    {
        $html = new SocializeExtension(new MediaUrls())->richText("Coucher de soleil <b>#Étretat</b> avec @hugo\net @alice@mastodon.social");

        self::assertStringContainsString('&lt;b&gt;', $html);
        self::assertStringContainsString('<span class="tag">#Étretat</span>', $html);
        self::assertStringContainsString('<a class="tag" href="/@hugo">@hugo</a>', $html);
        self::assertStringContainsString('@alice@mastodon.social</a>', $html);
        self::assertStringContainsString('<br>', $html);
    }

    public function testRelativeDates(): void
    {
        $ext = new SocializeExtension(new MediaUrls());
        $now = new \DateTimeImmutable('2026-11-12 12:00:00');

        self::assertSame('à l’instant', $ext->ago($now->modify('-10 seconds'), $now));
        self::assertSame('il y a 5 min', $ext->ago($now->modify('-5 minutes'), $now));
        self::assertSame('il y a 3 h', $ext->ago($now->modify('-3 hours'), $now));
        self::assertSame('il y a 2 j', $ext->ago($now->modify('-2 days'), $now));
        self::assertSame('14 juin 2019', $ext->ago(new \DateTimeImmutable('2019-06-14'), $now));
    }
}
