<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\System\SystemStatusProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sonde de santé pour Docker, un reverse proxy ou une supervision externe.
 */
#[AsController]
final class HealthController
{
    #[Route('/healthz', name: 'app_health', methods: ['GET'])]
    public function __invoke(Request $request, SystemStatusProvider $statusProvider): JsonResponse
    {
        $status = $statusProvider->current(WorkerMode::detect($request));

        $response = new JsonResponse(
            $status->toArray(),
            $status->isHealthy() ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
