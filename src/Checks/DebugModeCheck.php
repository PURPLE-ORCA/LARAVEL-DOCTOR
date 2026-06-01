<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Checks;

use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Contracts\DoctorCheckResult;

final class DebugModeCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Debug Mode';
    }

    public function category(): string
    {
        return 'security';
    }

    public function run(): DoctorCheckResult
    {
        $debug = config('app.debug');

        if ($debug === true) {
            $env = config('app.env', 'production');

            if ($env === 'production') {
                return DoctorCheckResult::fail(
                    'APP_DEBUG is ON in production',
                    'Set APP_DEBUG=false in your .env for production'
                );
            }

            return DoctorCheckResult::warn(
                "APP_DEBUG is ON (env: {$env})",
                'Make sure APP_DEBUG=false in production'
            );
        }

        return DoctorCheckResult::pass('APP_DEBUG is OFF');
    }
}
