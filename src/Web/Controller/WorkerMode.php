<?php

declare(strict_types=1);

namespace App\Web\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * FrankenPHP définit FRANKENPHP_WORKER quand l'application tourne en mode worker.
 */
final class WorkerMode
{
    public static function detect(Request $request): bool
    {
        return filter_var($request->server->get('FRANKENPHP_WORKER', false), \FILTER_VALIDATE_BOOL);
    }
}
