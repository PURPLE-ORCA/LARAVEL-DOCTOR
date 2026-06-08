<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class StorageWritableCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Storage Writable';
    }

    public function category(): string
    {
        return 'infrastructure';
    }

    public function run(): DoctorCheckResult
    {
        $paths = [
            'storage/app' => storage_path('app'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $failed = [];
        $checked = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path)) {
                $failed[] = "{$label} (missing)";
                continue;
            }

            if (! is_writable($path)) {
                $failed[] = "{$label} (not writable)";
            }

            $checked[] = $label;
        }

        if ($failed !== []) {
            $count = count($failed);
            $list = implode(', ', $failed);

            return DoctorCheckResult::fail(
                "{$count} storage path(s) are not writable: {$list}",
                'Run: chmod -R 775 ' . storage_path() . ' ' . base_path('bootstrap/cache'),
                'Non-writable storage prevents file uploads, cache writes, session storage, and log generation',
            );
        }

        return DoctorCheckResult::pass(
            'All ' . count($checked) . ' storage paths are writable'
        );
    }
}
