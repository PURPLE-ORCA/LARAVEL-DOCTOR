<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use Illuminate\Support\Facades\DB;
use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class DatabaseConnectionCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Database Connection';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        try {
            DB::connection()->getPdo();

            $database = config('database.connections.' . config('database.default') . '.database');

            return DoctorCheckResult::pass("Connected to database: {$database}");
        } catch (\Exception $e) {
            return DoctorCheckResult::fail(
                'Could not connect to database: ' . $e->getMessage(),
                'Check your DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD in .env'
            );
        }
    }
}
