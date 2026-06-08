<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class ViewCacheCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'View Cache';
    }

    public function category(): string
    {
        return 'performance';
    }

    public function run(): DoctorCheckResult
    {
        $cachePath = base_path('bootstrap/cache/views.php');

        if (file_exists($cachePath)) {
            $age = time() - filemtime($cachePath);
            $hours = (int) floor($age / 3600);

            if ($hours > 24 * 7) {
                return DoctorCheckResult::warn(
                    "Views cached but stale ({$hours}h old)",
                    'Run: php artisan view:cache',
                    'Stale view cache may miss updated Blade templates',
                );
            }

            return DoctorCheckResult::pass(
                "Views cached ({$hours}h ago)"
            );
        }

        return DoctorCheckResult::warn(
            'Views are not cached',
            'Run: php artisan view:cache for production',
            'Uncached Blade views are compiled on every request, adding 2-10ms overhead',
        );
    }
}
