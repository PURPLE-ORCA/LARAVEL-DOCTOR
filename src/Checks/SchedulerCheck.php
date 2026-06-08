<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class SchedulerCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Task Scheduler';
    }

    public function category(): string
    {
        return 'infrastructure';
    }

    public function run(): DoctorCheckResult
    {
        // Check if the scheduler cron entry exists in the system crontab
        $cronCheck = $this->hasSchedulerCron();

        if (! $cronCheck) {
            return DoctorCheckResult::warn(
                'Laravel scheduler is not configured in cron',
                'Add to crontab: * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
                'Without the scheduler cron entry, scheduled tasks (backups, reports, queue workers) will never run',
            );
        }

        return DoctorCheckResult::pass('Laravel scheduler cron entry is configured');
    }

    private function hasSchedulerCron(): bool
    {
        // Try to read the current user's crontab
        $crontab = @shell_exec('crontab -l 2>/dev/null');

        if ($crontab === null || $crontab === '') {
            return false;
        }

        // Look for schedule:run in the crontab
        return str_contains($crontab, 'schedule:run');
    }
}
