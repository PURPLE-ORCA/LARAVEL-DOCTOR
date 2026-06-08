<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\StorageWritableCheck;
use PurpleOrca\Doctor\Enums\Status;

it('checks storage paths are writable', function () {
    $check = new StorageWritableCheck;

    expect($check->name())->toBe('Storage Writable');
    expect($check->category())->toBe('infrastructure');

    $result = $check->run();

    // Result depends on environment — just verify it returns a valid status
    expect($result->status)->toBeInstanceOf(Status::class);
    expect($result->message)->not->toBeEmpty();
});
