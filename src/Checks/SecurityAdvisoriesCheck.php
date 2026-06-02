<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class SecurityAdvisoriesCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Security Advisories';
    }

    public function category(): string
    {
        return 'security';
    }

    public function run(): DoctorCheckResult
    {
        $composerPath = base_path('vendor/bin/composer');

        if (! file_exists($composerPath)) {
            $composerPath = 'composer';
        }

        $output = [];
        $exitCode = 0;

        exec(
            escapeshellcmd($composerPath) . ' audit --format=json 2>&1',
            $output,
            $exitCode
        );

        $json = implode("\n", $output);

        if ($exitCode === 0) {
            return DoctorCheckResult::pass('No known security advisories');
        }

        // Try to parse JSON output
        $data = json_decode($json, true);

        if (is_array($data) && isset($data['advisories'])) {
            $count = count($data['advisories']);

            return DoctorCheckResult::fail(
                "{$count} security advisories found",
                'Run: composer audit for details, then update affected packages'
            );
        }

        // Fallback: check if output mentions advisories
        if (str_contains($json, 'advisory') || str_contains($json, 'CVE')) {
            return DoctorCheckResult::fail(
                'Security advisories detected',
                'Run: composer audit for details'
            );
        }

        // composer audit not available or other error
        return DoctorCheckResult::warn(
            'Could not check security advisories',
            'Install Composer 2.4+ and run: composer audit'
        );
    }
}
