<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\AppKeyCheck;
use PurpleOrca\Doctor\Checks\DebugModeCheck;
use PurpleOrca\Doctor\Checks\MailMailerCheck;
use PurpleOrca\Doctor\Checks\PhpVersionCheck;
use PurpleOrca\Doctor\Enums\Status;

it('checks PHP version against composer.json requirement', function () {
    $check = new PhpVersionCheck;

    expect($check->name())->toBe('PHP Version');
    expect($check->category())->toBe('environment');

    $result = $check->run();

    // Current PHP should always pass against a default requirement
    expect($result->status)->toBe(Status::Pass);
});

it('checks if APP_KEY is set', function () {
    config(['app.key' => '']);

    $check = new AppKeyCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('APP_KEY is not set');
    expect($result->advice)->toContain('key:generate');
});

it('passes when APP_KEY is valid', function () {
    config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

    $check = new AppKeyCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
});

it('fails when debug mode is on in production', function () {
    config(['app.debug' => true, 'app.env' => 'production']);

    $check = new DebugModeCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('APP_DEBUG is ON in production');
});

it('passes when debug mode is off', function () {
    config(['app.debug' => false]);

    $check = new DebugModeCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
});

it('passes when debug mode is on in local', function () {
    config(['app.debug' => true, 'app.env' => 'local']);

    $check = new DebugModeCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toContain('expected for local dev');
});

it('fails when mail mailer is log in production', function () {
    config(['mail.default' => 'log', 'app.env' => 'production']);

    $check = new MailMailerCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('log" in production');
});

it('passes when mail mailer is log in local', function () {
    config(['mail.default' => 'log', 'app.env' => 'local']);

    $check = new MailMailerCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toContain('expected for local dev');
});
