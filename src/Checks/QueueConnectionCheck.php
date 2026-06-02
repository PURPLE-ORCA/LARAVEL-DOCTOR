<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class QueueConnectionCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'QUEUE_CONNECTION';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $connection = config('queue.default');

        if (blank($connection)) {
            return DoctorCheckResult::fail(
                'QUEUE_CONNECTION is not set',
                'Set QUEUE_CONNECTION in your .env (e.g., QUEUE_CONNECTION=sync)'
            );
        }

        return DoctorCheckResult::pass("QUEUE_CONNECTION is set to '{$connection}'");
    }
}
