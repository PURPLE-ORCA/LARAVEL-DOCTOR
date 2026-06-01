<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Checks;

use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Contracts\DoctorCheckResult;
use Illuminate\Support\Facades\Artisan;

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
        $configCached = file_exists(config_path('services.php'));

        // Check if config cache file exists
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

        return DoctorCheckResult::warn(
            'Config is not cached',
            'Run: php artisan config:cache for production'
        );
    }
}
