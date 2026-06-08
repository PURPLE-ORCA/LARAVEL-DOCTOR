<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\SchedulerCheck;
use PurpleOrca\Doctor\Enums\Status;

it('checks scheduler configuration', function () {
    $check = new SchedulerCheck;

    expect($check->name())->toBe('Task Scheduler');
    expect($check->category())->toBe('infrastructure');

    $result = $check->run();

    // Result depends on environment — just verify it returns a valid status
    expect($result->status)->toBeInstanceOf(Status::class);
    expect($result->message)->not->toBeEmpty();
});
