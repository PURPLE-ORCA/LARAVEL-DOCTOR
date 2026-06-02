<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use PurpleOrca\Doctor\DoctorServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            DoctorServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.env', 'testing');
        $app['config']->set('app.debug', false);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }
}
