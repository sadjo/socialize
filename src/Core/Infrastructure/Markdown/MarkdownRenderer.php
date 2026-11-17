<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Markdown écrit par l'administrateur, rendu sans risque : HTML brut retiré, liens dangereux (javascript:…) refusés,
 * liens externes ouverts dans un nouvel onglet.
 */
final class MarkdownRenderer
{
    private ?MarkdownConverter $converter = null;

    public function __construct(
        #[Autowire(param: 'app.local_domain')]
        private readonly string $localDomain,
    ) {
    }

    public function toHtml(string $markdown): string
    {
        return $this->converter()->convert($markdown)->getContent();
    }

    private function converter(): MarkdownConverter
    {
        if (!$this->converter instanceof MarkdownConverter) {
            $environment = new Environment([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 20,
                'external_link' => [
                    'internal_hosts' => [$this->localDomain],
                    'open_in_new_window' => true,
                    'nofollow' => 'external',
                    'noopener' => 'external',
                    'noreferrer' => '',
                ],
                'default_attributes' => [
                    Image::class => ['loading' => 'lazy'],
                ],
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new AutolinkExtension());
            $environment->addExtension(new StrikethroughExtension());
            $environment->addExtension(new ExternalLinkExtension());
            $environment->addExtension(new DefaultAttributesExtension());
            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter;
    }
}
