<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class MaintenanceModeCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Maintenance Mode';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $maintenancePath = storage_path('framework/down');

        if (file_exists($maintenancePath)) {
            $data = json_decode(file_get_contents($maintenancePath), true);
            $message = $data['message'] ?? 'Application is in maintenance mode';

            return DoctorCheckResult::warn(
                "App is in maintenance mode: {$message}",
                'Run: php artisan up to bring the app back online'
            );
        }

        return DoctorCheckResult::pass('App is not in maintenance mode');
    }
}
