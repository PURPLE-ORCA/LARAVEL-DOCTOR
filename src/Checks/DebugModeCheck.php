<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

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
        $env = config('app.env', 'production');

        if ($debug === true) {
            if ($env === 'production') {
                return DoctorCheckResult::fail(
                    'APP_DEBUG is ON in production',
                    'Set APP_DEBUG=false in your .env for production',
                    'Exposes stack traces and sensitive config to end users',
                    'https://laravel.com/docs/configuration#debug-mode',
                );
            }

            return DoctorCheckResult::pass("APP_DEBUG is ON (env: {$env} — expected for local dev)");
        }

        return DoctorCheckResult::pass('APP_DEBUG is OFF');
    }
}
