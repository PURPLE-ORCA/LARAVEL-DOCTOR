<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor;

use PurpleOrca\Doctor\Commands\DoctorCommand;
use Illuminate\Support\ServiceProvider;

final class DoctorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/doctor.php',
            'doctor'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        //
    }
}
