<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class SessionDriverCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'SESSION_DRIVER';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $driver = config('session.driver');

        if (blank($driver)) {
            return DoctorCheckResult::fail(
                'SESSION_DRIVER is not set',
                'Set SESSION_DRIVER in your .env (e.g., SESSION_DRIVER=file)'
            );
        }

        return DoctorCheckResult::pass("SESSION_DRIVER is set to '{$driver}'");
    }
}
