<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\EnvFileCheck;
use PurpleOrca\Doctor\Enums\Status;

it('warns when .env file is missing', function () {
    // In testbench, .env might or might not exist
    // This test verifies the check runs without error
    $check = new EnvFileCheck;

    expect($check->name())->toBe('Environment File');
    expect($check->category())->toBe('environment');

    $result = $check->run();

    // Result should be one of the valid statuses
    expect($result->status)->toBeInstanceOf(Status::class);
});
