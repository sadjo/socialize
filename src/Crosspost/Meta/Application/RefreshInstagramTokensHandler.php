<?php

declare(strict_types=1);

namespace App\Crosspost\Meta\Application;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshInstagramTokensHandler
{
    public function __construct(
        private InstagramConnections $connections,
    ) {
    }

    public function __invoke(RefreshInstagramTokens $message): void
    {
        $this->connections->refreshDue();
    }
}
