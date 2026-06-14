<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Empty default schedule. No recurring messages in Phase 0 — its presence
 * provisions the `scheduler_default` transport so the compose `scheduler` service
 * (`messenger:consume scheduler_default`) is a real, wired slot ready for the
 * first recurring job.
 */
#[AsSchedule('default')]
final class DefaultSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule();
    }
}
