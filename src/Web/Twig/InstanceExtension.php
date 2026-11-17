<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Application\Instance\InstanceAbout;
use App\Core\Infrastructure\Markdown\MarkdownRenderer;
use Twig\Attribute\AsTwigFunction;

final readonly class InstanceExtension
{
    public function __construct(
        private InstanceAbout $about,
        private MarkdownRenderer $markdown,
    ) {
    }

    /**
     * Bloc « Instance » : titre en texte simple, présentation rendue depuis le Markdown.
     *
     * @return array{title: string, html: string}
     */
    #[AsTwigFunction('instance_about')]
    public function instanceAbout(): array
    {
        return ['title' => $this->about->title(), 'html' => $this->markdown->toHtml($this->about->body())];
    }
}
