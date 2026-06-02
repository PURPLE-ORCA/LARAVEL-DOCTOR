<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class AppKeyCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'APP_KEY';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $key = config('app.key');

        if (blank($key)) {
            return DoctorCheckResult::fail(
                'APP_KEY is not set',
                'Run: php artisan key:generate'
            );
        }

        $length = strlen($key);

        // Laravel 10+ uses 32-byte keys encoded as base64
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7));

            if ($decoded === false || strlen($decoded) < 32) {
                return DoctorCheckResult::fail(
                    'APP_KEY is too short (decoded: ' . strlen($decoded) . ' bytes, need 32)',
                    'Run: php artisan key:generate'
                );
            }
        }

        return DoctorCheckResult::pass(
            "APP_KEY is set ({$length} chars)"
        );
    }
}
