<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Contracts;

use Sahraoui\Doctor\Enums\Status;

final class DoctorCheckResult
{
    public function __construct(
        public readonly Status $status,
        public readonly string $message,
        public readonly ?string $advice = null,
    ) {}

    public static function pass(string $message): self
    {
        return new self(Status::Pass, $message);
    }

    public static function warn(string $message, ?string $advice = null): self
    {
        return new self(Status::Warn, $message, $advice);
    }

    public static function fail(string $message, ?string $advice = null): self
    {
        return new self(Status::Fail, $message, $advice);
    }
}
