<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\OpCacheCheck;
use PurpleOrca\Doctor\Enums\Status;

it('checks OPcache status', function () {
    $check = new OpCacheCheck;

    expect($check->name())->toBe('OPcache');
    expect($check->category())->toBe('performance');

    $result = $check->run();

    // Result depends on environment — just verify it returns a valid status
    expect($result->status)->toBeInstanceOf(Status::class);
    expect($result->message)->not->toBeEmpty();
});
