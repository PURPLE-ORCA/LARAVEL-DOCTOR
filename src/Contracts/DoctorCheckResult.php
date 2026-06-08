<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Contracts;

use PurpleOrca\Doctor\Enums\Status;

final class DoctorCheckResult
{
    public function __construct(
        public readonly Status $status,
        public readonly string $message,
        public readonly ?string $advice = null,
        public readonly ?string $impact = null,
        public readonly ?string $docsUrl = null,
    ) {}

    public static function pass(string $message): self
    {
        return new self(Status::Pass, $message);
    }

    public static function warn(string $message, ?string $advice = null, ?string $impact = null, ?string $docsUrl = null): self
    {
        return new self(Status::Warn, $message, $advice, $impact, $docsUrl);
    }

    public static function fail(string $message, ?string $advice = null, ?string $impact = null, ?string $docsUrl = null): self
    {
        return new self(Status::Fail, $message, $advice, $impact, $docsUrl);
    }
}
