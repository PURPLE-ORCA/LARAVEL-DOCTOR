<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use Illuminate\Support\Facades\Artisan;
use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;
use Symfony\Component\Process\Process;

final class MigrationStatusCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Migration Status';
    }

    public function category(): string
    {
        return 'infrastructure';
    }

    public function run(): DoctorCheckResult
    {
        try {
            // Try using Artisan facade first
            $output = Artisan::output();
            Artisan::call('migrate:status', ['--pending' => true]);
            $output = Artisan::output();

            if (str_contains($output, 'No pending migrations')) {
                return DoctorCheckResult::pass('All migrations are up to date');
            }

            // Count pending migrations
            $pendingCount = substr_count($output, 'Pending');
            if ($pendingCount === 0) {
                return DoctorCheckResult::pass('All migrations are up to date');
            }

            return DoctorCheckResult::warn(
                "{$pendingCount} pending migration(s) found",
                'Run: php artisan migrate',
                'Pending migrations may cause schema mismatches and application errors',
            );
        } catch (\Throwable $e) {
            // Fallback: try process-based check
            return $this->fallbackCheck();
        }
    }

    private function fallbackCheck(): DoctorCheckResult
    {
        try {
            $process = new Process(['php', 'artisan', 'migrate:status', '--pending']);
            $process->setWorkingDirectory(base_path());
            $process->setTimeout(30);
            $process->run();

            $output = $process->getOutput();

            if (str_contains($output, 'No pending migrations') || ! str_contains($output, 'Pending')) {
                return DoctorCheckResult::pass('All migrations are up to date');
            }

            return DoctorCheckResult::warn(
                'Pending migrations found',
                'Run: php artisan migrate',
                'Pending migrations may cause schema mismatches and application errors',
            );
        } catch (\Throwable $e) {
            return DoctorCheckResult::warn(
                'Unable to check migration status',
                'Ensure database is configured and migrations table exists',
            );
        }
    }
}
