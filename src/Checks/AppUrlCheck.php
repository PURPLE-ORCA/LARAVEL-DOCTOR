<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class AppUrlCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'APP_URL';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $url = config('app.url');

        if (blank($url)) {
            return DoctorCheckResult::fail(
                'APP_URL is not set',
                'Set APP_URL in your .env (e.g., APP_URL=http://localhost:8000)'
            );
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return DoctorCheckResult::warn(
                "APP_URL is not a valid URL: {$url}",
                'Ensure APP_URL is a valid URL (e.g., https://example.com)'
            );
        }

        return DoctorCheckResult::pass("APP_URL is set ({$url})");
    }
}
