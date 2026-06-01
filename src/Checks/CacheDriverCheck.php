<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Checks;

use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Contracts\DoctorCheckResult;

final class CacheDriverCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'CACHE_DRIVER';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $driver = config('cache.default');

        if (blank($driver)) {
            return DoctorCheckResult::fail(
                'CACHE_DRIVER is not set',
                'Set CACHE_DRIVER in your .env (e.g., CACHE_DRIVER=file)'
            );
        }

        return DoctorCheckResult::pass("CACHE_DRIVER is set to '{$driver}'");
    }
}
