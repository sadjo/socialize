<?php

declare(strict_types=1);

namespace App\Federation\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ActivityJsonResponse extends JsonResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data, int $status = 200, string $contentType = 'application/activity+json')
    {
        parent::__construct($data, $status, ['Content-Type' => $contentType.'; charset=utf-8', 'Vary' => 'Accept']);
        $this->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $this->setPublic();
        $this->setMaxAge(60);
    }

    /**
     * La requête demande-t-elle du JSON ActivityPub plutôt qu'une page HTML ?
     */
    public static function wanted(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept');

        return str_contains($accept, 'application/activity+json') || str_contains($accept, 'application/ld+json');
    }
}
