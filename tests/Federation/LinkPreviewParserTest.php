<?php

declare(strict_types=1);

namespace App\Tests\Federation;

use App\Federation\Application\LinkPreviewParser;
use PHPUnit\Framework\TestCase;

/**
 * Lecture d'une page pour l'aperçu d'un lien : balises Open Graph, à défaut le titre et la description de la page ;
 * premier lien d'un texte.
 */
final class LinkPreviewParserTest extends TestCase
{
    public function testOpenGraphTagsAreRead(): void
    {
        $page = LinkPreviewParser::parse('<html><head><meta property="og:site_name" content="GitHub"><meta property="og:title" content="  Panneau   de contrôle "><meta property="og:description" content="Libre &amp; auto-hébergé"><meta property="og:image" content="//cdn.example.org/a.png"></head></html>', 'https://github.com/a/b');

        self::assertSame(['site' => 'GitHub', 'title' => 'Panneau de contrôle', 'description' => 'Libre & auto-hébergé', 'image' => 'https://cdn.example.org/a.png'], $page);
    }

    public function testThePageTitleAndDescriptionAreUsedWithoutOpenGraph(): void
    {
        $page = LinkPreviewParser::parse('<html><head><title>Mon blog</title><meta name="description" content="Notes du dimanche"><meta property="og:image" content="images/une.jpg"></head></html>', 'https://blog.example.org/2026/note.html');

        self::assertSame('Mon blog', $page['title']);
        self::assertSame('Notes du dimanche', $page['description']);
        self::assertNull($page['site']);
        self::assertSame('https://blog.example.org/2026/images/une.jpg', $page['image'], 'Image relative résolue par rapport à la page.');
        self::assertNull(LinkPreviewParser::parse('<meta property="og:image" content="javascript:alert(1)">', 'https://example.org/')['image'], 'Seulement http(s).');
    }

    public function testTheFirstLinkOfATextIsFound(): void
    {
        self::assertSame('https://example.org/page', LinkPreviewParser::firstLink('Regardez https://example.org/page puis https://autre.example'));
        self::assertSame('https://www.example.org', LinkPreviewParser::firstLink('Sur www.example.org #tag @alice'));
        self::assertNull(LinkPreviewParser::firstLink('Aucun lien, juste #tag et @alice'));
    }
}
