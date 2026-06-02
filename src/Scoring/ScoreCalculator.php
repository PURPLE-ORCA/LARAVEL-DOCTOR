<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Scoring;

use PurpleOrca\Doctor\Contracts\DoctorCheckResult;
use PurpleOrca\Doctor\Enums\Status;

final class ScoreCalculator
{
    public function calculate(array $results): int
    {
        $deductions = 0;

        foreach ($results as $result) {
            $deductions += match ($result->status) {
                Status::Fail => 3,
                Status::Warn => 1,
                Status::Pass => 0,
            };
        }

        return max(0, 100 - $deductions);
    }

    public function breakdown(array $results): array
    {
        $counts = [
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
        ];

        foreach ($results as $result) {
            $counts[$result->status->value]++;
        }

        return $counts;
    }
}
