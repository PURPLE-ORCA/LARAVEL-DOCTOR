<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\ViewCacheCheck;
use PurpleOrca\Doctor\Enums\Status;

it('warns when views are not cached', function () {
    $check = new ViewCacheCheck;

    expect($check->name())->toBe('View Cache');
    expect($check->category())->toBe('performance');

    $result = $check->run();

    expect($result->status)->toBeInstanceOf(Status::class);
    expect($result->message)->toContain('not cached');
});
