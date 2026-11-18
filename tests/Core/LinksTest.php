<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Application\Post\Links;
use App\Federation\Application\HtmlText;
use PHPUnit\Framework\TestCase;

final class LinksTest extends TestCase
{
    public function testSplitKeepsTrailingPunctuationOutOfTheAddress(): void
    {
        self::assertSame([
            [false, 'Voir '],
            [true, 'https://exemple.fr/a_(b)'],
            [false, ', puis ('],
            [true, 'http://exemple.org/x'],
            [false, ').'],
        ], Links::split('Voir https://exemple.fr/a_(b), puis (http://exemple.org/x).'));
        self::assertSame([[false, 'pas de lien : ftp://x.fr ni xhttps://y.fr']], Links::split('pas de lien : ftp://x.fr ni xhttps://y.fr'));
    }

    public function testAddressesWithoutSchemeAreRecognized(): void
    {
        $links = static fn (string $text): array => array_values(array_map(static fn (array $p): string => $p[1], array_filter(Links::split($text), static fn (array $p): bool => $p[0])));

        self::assertSame(['www.exemple.fr', 'exemple.fr/agenda?id=2#jour', 'blog.exemple.social', 'www.musee.museum', 'exemple.org:8080/x'], $links('Voir www.exemple.fr, exemple.fr/agenda?id=2#jour et blog.exemple.social. Aussi www.musee.museum (exemple.org:8080/x).'));
        self::assertSame([], $links('photo.jpg, IMG_12.JPG, v0.5.1, 3.14, Node.js, fin.Merci, exemple.com.jpg, contact@exemple.fr, @alice@mastodon.social, #exemple.fr, exemple.fra'));
        self::assertSame('https://www.exemple.fr', Links::href('www.exemple.fr'));
        self::assertSame('http://exemple.fr', Links::href('http://exemple.fr'));
        self::assertSame(['www.', 'exemple.fr', ''], Links::display('www.exemple.fr'));
        self::assertSame(['', 'exemple.fr/agenda', ''], Links::display('exemple.fr/agenda'));
    }

    public function testFederatedHtmlLinksAddressesWithoutScheme(): void
    {
        self::assertSame(
            '<p>Voir <a href="https://www.exemple.fr" rel="nofollow noopener noreferrer" target="_blank"><span class="invisible">www.</span><span class="">exemple.fr</span><span class="invisible"></span></a>.</p>',
            HtmlText::toHtml('Voir www.exemple.fr.', static fn (string $t): string => '/tags/'.$t, static fn (string $h): string => '/@'.$h),
        );
    }

    public function testHashtagsAndMentionsInsideAnAddressAreIgnored(): void
    {
        self::assertSame(['expo'], HtmlText::hashtags('https://exemple.fr/page#jour-2 #expo'));
        self::assertSame('https://x.test/?u=@hugo ·', Links::outside('https://x.test/?u=@hugo @hugo', static fn (string $s): string => str_replace('@hugo', '·', $s)));
    }

    public function testLongAddressesAreShortenedForDisplay(): void
    {
        self::assertSame(['https://www.', 'exemple.fr', ''], Links::display('https://www.exemple.fr'));
        self::assertSame(['https://', 'exemple.fr/un/chemin/assez/lon', 'g/vraiment'], Links::display('https://exemple.fr/un/chemin/assez/long/vraiment'));
    }
}
