<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Checks;

use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Contracts\DoctorCheckResult;

final class PhpVersionCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'PHP Version';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $required = $this->getRequiredVersion();
        $current = PHP_VERSION;

        if (version_compare($current, $required, '>=')) {
            return DoctorCheckResult::pass(
                "PHP {$current} (requires >= {$required})"
            );
        }

        return DoctorCheckResult::fail(
            "PHP {$current} is below the required {$required}",
            "Upgrade PHP to {$required} or later"
        );
    }

    private function getRequiredVersion(): string
    {
        $composerPath = base_path('composer.json');

        if (! file_exists($composerPath)) {
            return '8.1';
        }

        $composer = json_decode(file_get_contents($composerPath), true);

        $required = $composer['require']['php'] ?? '8.1';

        // Extract version number from constraint like "^8.1" or ">=8.1"
        if (preg_match('/(\d+\.\d+)/', $required, $matches)) {
            return $matches[1];
        }

        return '8.1';
    }
}
