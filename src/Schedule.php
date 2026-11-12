<?php

declare(strict_types=1);

namespace App;

use App\Crosspost\Meta\Application\RefreshInstagramTokens;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // Jetons Instagram (60 jours) : renouvelés chaque jour s'ils ont plus d'une semaine.
            ->add(RecurringMessage::every('1 day', new RefreshInstagramTokens()))
        ;
    }
}
