<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class RouteCacheCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Route Cache';
    }

    public function category(): string
    {
        return 'performance';
    }

    public function run(): DoctorCheckResult
    {
        $cachePath = base_path('bootstrap/cache/routes-v7.php');

        if (file_exists($cachePath)) {
            $age = time() - filemtime($cachePath);
            $hours = (int) floor($age / 3600);

            if ($hours > 24 * 7) {
                return DoctorCheckResult::warn(
                    "Routes cached but stale ({$hours}h old)",
                    'Run: php artisan route:cache',
                    'Stale route cache may miss new routes or middleware changes',
                );
            }

            return DoctorCheckResult::pass(
                "Routes cached ({$hours}h ago)"
            );
        }

        return DoctorCheckResult::warn(
            'Routes are not cached',
            'Run: php artisan route:cache for production',
            'Uncached routes are parsed on every request, adding 5-20ms overhead',
        );
    }
}
