<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\MigrationStatusCheck;
use PurpleOrca\Doctor\Enums\Status;

it('checks migration status', function () {
    $check = new MigrationStatusCheck;

    expect($check->name())->toBe('Migration Status');
    expect($check->category())->toBe('infrastructure');

    $result = $check->run();

    // Result depends on environment — just verify it returns a valid status
    expect($result->status)->toBeInstanceOf(Status::class);
    expect($result->message)->not->toBeEmpty();
});
