<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Checks;

use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Contracts\DoctorCheckResult;

final class StorageLinkCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Storage Link';
    }

    public function category(): string
    {
        return 'performance';
    }

    public function run(): DoctorCheckResult
    {
        $linkPath = public_path('storage');

        if (is_link($linkPath)) {
            $target = readlink($linkPath);

            return DoctorCheckResult::pass("Storage link exists → {$target}");
        }

        if (is_dir($linkPath)) {
            return DoctorCheckResult::warn(
                'public/storage is a directory, not a symlink',
                'Run: php artisan storage:link'
            );
        }

        return DoctorCheckResult::fail(
            'public/storage symlink does not exist',
            'Run: php artisan storage:link'
        );
    }
}
