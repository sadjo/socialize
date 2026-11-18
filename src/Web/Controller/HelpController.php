<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Web\Help\HelpChapter;
use App\Web\Help\HelpGuide;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aide (guide d'utilisation), lisible sans compte ; le chapitre d'administration est réservé aux administrateurs.
 */
final class HelpController extends AbstractController
{
    public function __construct(
        private readonly HelpGuide $guide,
    ) {
    }

    #[Route('/aide', name: 'app_help', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('help/index.html.twig', ['chapters' => $this->guide->chapters($this->isGranted('ROLE_ADMIN'))]);
    }

    #[Route('/aide/{slug}', name: 'app_help_page', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function page(string $slug): Response
    {
        $chapters = $this->guide->chapters($this->isGranted('ROLE_ADMIN'));
        $index = array_find_key($chapters, static fn (HelpChapter $c): bool => $c->slug === $slug);
        if (null === $index) {
            throw $this->createNotFoundException('Cette page d’aide n’existe pas.');
        }

        return $this->render('help/page.html.twig', [
            'chapters' => $chapters,
            'chapter' => $chapters[$index],
            'html' => $this->guide->html($chapters[$index]),
            'previous' => $chapters[$index - 1] ?? null,
            'next' => $chapters[$index + 1] ?? null,
        ]);
    }
}
