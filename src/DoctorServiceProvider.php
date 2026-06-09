<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor;

use PurpleOrca\Doctor\Commands\DoctorCommand;
use Illuminate\Support\ServiceProvider;

final class DoctorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/doctor.php' => config_path('doctor.php'),
            ], 'doctor');

            $this->commands([
                DoctorCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/doctor.php',
            'doctor'
        );
    }
}
