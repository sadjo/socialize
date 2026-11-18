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
        self::assertStringContainsString('<a class="tag" href="/@hugo"><span class="mi mi-local" aria-hidden="true"></span>@hugo</a>', $html, 'Compte d’ici : pastille Socialize.');
        self::assertStringContainsString('<a class="tag" href="/@alice@mastodon.social"><span class="mi mi-fedi" aria-hidden="true"></span>@alice@mastodon.social</a>', $html, 'Compte du fédivers : pastille Mastodon.');
        self::assertStringContainsString('<br>', $html);
    }

    public function testMentionsOfImportedTextLeadToInstagramProfiles(): void
    {
        $html = new SocializeExtension(new MediaUrls())->richText('Rando avec @hugo.martin et @alice@mastodon.social', instagram: true);

        self::assertStringContainsString('<a class="tag" href="https://www.instagram.com/hugo.martin/" target="_blank" rel="noopener nofollow"><span class="mi mi-ig" aria-hidden="true"></span>@hugo.martin</a>', $html);
        self::assertStringContainsString('<a class="tag" href="/@alice@mastodon.social"><span class="mi mi-fedi" aria-hidden="true"></span>@alice@mastodon.social</a>', $html, 'Une adresse du fédivers reste un compte du fédivers.');
    }

    public function testInstagramMentions(): void
    {
        $html = new SocializeExtension(new MediaUrls())->richText('Vu chez ig:@natgeo et @hugo, mais pas tig:@x ni a@b');

        self::assertStringContainsString('<a class="tag ig" href="https://www.instagram.com/natgeo/" target="_blank" rel="noopener nofollow" title="Compte Instagram"><span class="mi mi-ig" aria-hidden="true"></span>@natgeo</a>', $html);
        self::assertStringContainsString('<a class="tag" href="/@hugo"><span class="mi mi-local" aria-hidden="true"></span>@hugo</a>', $html);
        self::assertStringNotContainsString('ig:@natgeo', $html);
        self::assertStringContainsString('tig:@x', $html, '« ig: » collé à un mot n’est pas une mention Instagram.');
        self::assertStringContainsString('a@b', $html);
    }

    public function testWebAddressesBecomeLinks(): void
    {
        $html = new SocializeExtension(new MediaUrls())->richText("Le programme : https://www.exemple.fr/agenda/2026/novembre/festival-photo?id=12#jour-2.\n(voir http://exemple.org) et <https://x.test/@hugo>");

        self::assertStringContainsString('<a class="link" href="https://www.exemple.fr/agenda/2026/novembre/festival-photo?id=12#jour-2" target="_blank" rel="noopener nofollow ugc" title="https://www.exemple.fr/agenda/2026/novembre/festival-photo?id=12#jour-2">exemple.fr/agenda/2026/novembr…</a>.<br>', $html, 'Adresse raccourcie, sans le point final ni de hashtag dans l’ancre.');
        self::assertStringContainsString('(voir <a class="link" href="http://exemple.org" target="_blank" rel="noopener nofollow ugc" title="http://exemple.org">exemple.org</a>)', $html, 'La parenthèse fermante n’est pas dans l’adresse.');
        self::assertStringContainsString('&lt;<a class="link" href="https://x.test/@hugo"', $html, 'Ni mention dans une adresse, ni chevron.');
        self::assertStringNotContainsString('mi-local', $html);
    }

    public function testAnAddressWithoutHostStaysText(): void
    {
        $html = new SocializeExtension(new MediaUrls())->richText('https:// et https://" onmouseover="x');

        self::assertStringNotContainsString('<a', $html);
        self::assertStringContainsString('&quot;', $html);
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
