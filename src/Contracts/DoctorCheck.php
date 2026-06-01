<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Contracts;

use Sahraoui\Doctor\Enums\Status;

interface DoctorCheck
{
    /** Human-readable check name */
    public function name(): string;

    /** Category for grouping (environment, security, performance) */
    public function category(): string;

    /** Run the check and return the result */
    public function run(): DoctorCheckResult;
}
