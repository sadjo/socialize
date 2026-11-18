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
