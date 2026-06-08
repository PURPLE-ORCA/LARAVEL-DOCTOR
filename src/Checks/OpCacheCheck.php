<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class OpCacheCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'OPcache';
    }

    public function category(): string
    {
        return 'performance';
    }

    public function run(): DoctorCheckResult
    {
        if (! extension_loaded('Zend OPcache')) {
            return DoctorCheckResult::warn(
                'OPcache extension is not loaded',
                'Enable OPcache in php.ini: zend_extension=opcache',
                'Without OPcache, PHP files are recompiled on every request, adding 20-50ms overhead',
            );
        }

        $enabled = ini_get('opcache.enable');
        if (! $enabled || $enabled === '0') {
            return DoctorCheckResult::warn(
                'OPcache is loaded but disabled',
                'Set opcache.enable=1 in php.ini',
                'OPcache disabled means no bytecode caching benefit',
            );
        }

        $status = opcache_get_status(false);
        if ($status === false) {
            return DoctorCheckResult::warn(
                'OPcache status unavailable (opcache.restrict_api may be set)',
                'Check opcache.restrict_api in php.ini or verify CLI vs FPM configuration',
            );
        }

        $hitRate = $status['opcache_statistics']['opcache_hit_rate'] ?? 0;
        $memoryUsage = $status['memory_usage']['used_memory'] ?? 0;
        $memoryFree = $status['memory_usage']['free_memory'] ?? 1;
        $memoryTotal = $memoryUsage + $memoryFree;
        $memoryPercent = $memoryTotal > 0 ? round(($memoryUsage / $memoryTotal) * 100, 1) : 0;

        if ($hitRate < 80) {
            return DoctorCheckResult::warn(
                "OPcache hit rate is low ({$hitRate}%)",
                'Increase opcache.memory_consumption and opcache.max_accelerated_files',
                "Low hit rate means {$hitRate}% of requests recompile PHP bytecode",
            );
        }

        if ($memoryPercent > 85) {
            return DoctorCheckResult::warn(
                "OPcache memory nearly full ({$memoryPercent}%)",
                'Increase opcache.memory_consumption in php.ini',
                'Nearly full OPcache causes evictions and reduced hit rates',
            );
        }

        return DoctorCheckResult::pass(
            "OPcache enabled — hit rate {$hitRate}%, memory {$memoryPercent}% used"
        );
    }
}
