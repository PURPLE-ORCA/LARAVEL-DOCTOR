<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Checks;

use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Contracts\DoctorCheckResult;

final class EnvFileCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Environment File';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return DoctorCheckResult::fail(
                '.env file not found',
                'Run: cp .env.example .env && php artisan key:generate'
            );
        }

        $size = filesize($envPath);

        if ($size === 0) {
            return DoctorCheckResult::warn(
                '.env file is empty',
                'Populate your .env with the required values'
            );
        }

        return DoctorCheckResult::pass('.env file exists (' . round($size / 1024, 1) . 'KB)');
    }
}
