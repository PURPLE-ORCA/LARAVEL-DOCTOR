<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Scoring;

use Sahraoui\Doctor\Contracts\DoctorCheckResult;
use Sahraoui\Doctor\Enums\Status;

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
