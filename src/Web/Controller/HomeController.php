<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\System\SystemStatusProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(Request $request, SystemStatusProvider $statusProvider): Response
    {
        return $this->render('home/index.html.twig', [
            'status' => $statusProvider->current(WorkerMode::detect($request)),
        ]);
    }
}
