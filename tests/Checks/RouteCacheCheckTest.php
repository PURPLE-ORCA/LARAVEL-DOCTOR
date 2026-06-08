<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\RouteCacheCheck;
use PurpleOrca\Doctor\Enums\Status;

it('warns when routes are not cached', function () {
    $check = new RouteCacheCheck;

    expect($check->name())->toBe('Route Cache');
    expect($check->category())->toBe('performance');

    $result = $check->run();

    // In testbench, routes are typically not cached
    expect($result->status)->toBeInstanceOf(Status::class);
    expect($result->message)->toContain('not cached');
});
