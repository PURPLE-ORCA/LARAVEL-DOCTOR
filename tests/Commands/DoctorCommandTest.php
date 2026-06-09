<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

it('returns failure for json output when any check fails', function () {
    config()->set('doctor.checks', [JsonFailingCheck::class]);

    $this->artisan('doctor', ['--json' => true])->assertExitCode(1);
});

it('returns success for json output when all checks pass', function () {
    config()->set('doctor.checks', [JsonPassingCheck::class]);

    $this->artisan('doctor', ['--json' => true, '--category' => 'json-test'])->assertExitCode(0);
});

final class JsonFailingCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'JSON failing check';
    }

    public function category(): string
    {
        return 'json-test';
    }

    public function run(): DoctorCheckResult
    {
        return DoctorCheckResult::fail('JSON mode should fail when any check fails');
    }
}

final class JsonPassingCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'JSON passing check';
    }

    public function category(): string
    {
        return 'json-test';
    }

    public function run(): DoctorCheckResult
    {
        return DoctorCheckResult::pass('JSON mode should succeed when checks pass');
    }
}
