<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class CacheStatusCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Config Cache';
    }

    public function category(): string
    {
        return 'performance';
    }

    public function run(): DoctorCheckResult
    {
        $env = config('app.env', 'production');
        $cachePath = base_path('bootstrap/cache/config.php');
        $cached = file_exists($cachePath);

        if ($cached) {
            $age = time() - filemtime($cachePath);
            $hours = (int) floor($age / 3600);

            if ($hours > 24 * 7) {
                return DoctorCheckResult::warn(
                    "Config cached but stale ({$hours}h old)",
                    'Run: php artisan config:cache'
                );
            }

            return DoctorCheckResult::pass(
                "Config cached ({$hours}h ago)"
            );
        }

        if ($env !== 'production') {
            return DoctorCheckResult::pass("Config is not cached (env: {$env} — expected for local dev)");
        }

        return DoctorCheckResult::warn(
            'Config is not cached',
            'Run: php artisan config:cache for production',
            'Uncached config is parsed on every request, adding overhead'
        );
    }
}
