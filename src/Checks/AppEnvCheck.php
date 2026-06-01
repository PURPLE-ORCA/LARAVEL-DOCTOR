<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Checks;

use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Contracts\DoctorCheckResult;

final class AppEnvCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'APP_ENV';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $env = config('app.env');

        if (blank($env)) {
            return DoctorCheckResult::fail(
                'APP_ENV is not set',
                'Set APP_ENV in your .env (e.g., APP_ENV=local or APP_ENV=production)'
            );
        }

        return DoctorCheckResult::pass("APP_ENV is set to '{$env}'");
    }
}
