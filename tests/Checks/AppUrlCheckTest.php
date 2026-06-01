<?php

declare(strict_types=1);

use Sahraoui\Doctor\Checks\AppUrlCheck;
use Sahraoui\Doctor\Enums\Status;

it('checks APP_URL configuration', function () {
    $check = new AppUrlCheck;

    expect($check->name())->toBe('APP_URL');
    expect($check->category())->toBe('environment');

    $result = $check->run();

    expect($result->status)->toBeInstanceOf(Status::class);
});
