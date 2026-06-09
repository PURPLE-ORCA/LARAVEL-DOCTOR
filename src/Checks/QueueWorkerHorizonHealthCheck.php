<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class QueueWorkerHorizonHealthCheck implements DoctorCheck
{
    private const DOCS_URL = 'https://laravel.com/docs/12.x/queues';

    public function __construct(
        private readonly ?string $rootPath = null,
    ) {}

    public function name(): string
    {
        return 'Queue Worker / Horizon Health';
    }

    public function category(): string
    {
        return 'infrastructure';
    }

    public function run(): DoctorCheckResult
    {
        $environment = (string) config('app.env', 'production');
        $queueConnection = (string) config('queue.default', 'sync');
        $queuedUsage = $this->findQueuedWorkUsage();

        if ($this->usesHorizon()) {
            $horizonIssue = $this->findHorizonIssue($queueConnection);
            if ($horizonIssue !== null) {
                return $horizonIssue;
            }
        }

        if ($environment === 'production' && $queuedUsage !== null && $queueConnection === 'sync') {
            return DoctorCheckResult::fail(
                sprintf(
                    'Queued work is used in %s but QUEUE_CONNECTION=sync in production',
                    $queuedUsage,
                ),
                'Use a real async queue backend such as database or redis before dispatching ShouldQueue jobs in production.',
                'Sync queueing makes background work run inline, hides worker failures, and can break UX when jobs become slow.',
                self::DOCS_URL,
            );
        }

        if ($queueConnection === 'database') {
            $databaseIssue = $this->findDatabaseQueueIssue();
            if ($databaseIssue !== null) {
                return $databaseIssue;
            }
        }

        return DoctorCheckResult::pass('No obvious queue worker or Horizon health risks found');
    }

    private function findHorizonIssue(string $queueConnection): ?DoctorCheckResult
    {
        if ($queueConnection !== 'redis') {
            return DoctorCheckResult::fail(
                sprintf('Horizon appears to be enabled but QUEUE_CONNECTION is `%s` instead of `redis`', $queueConnection),
                'Set QUEUE_CONNECTION=redis (or disable Horizon for this app) so Horizon supervises a supported backend.',
                'Horizon only manages Redis-backed queues; a mismatched backend makes queue supervision misleading or broken.',
                'https://laravel.com/docs/12.x/horizon',
            );
        }

        return null;
    }

    private function findDatabaseQueueIssue(): ?DoctorCheckResult
    {
        $missingTables = [];

        if (! Schema::hasTable('jobs')) {
            $missingTables[] = 'jobs';
        }

        if (! Schema::hasTable('failed_jobs')) {
            $missingTables[] = 'failed_jobs';
        }

        if ($missingTables !== []) {
            return DoctorCheckResult::fail(
                'Database queue is configured but required queue tables are missing: ' . implode(', ', $missingTables),
                'Run `php artisan queue:table`, `php artisan queue:failed-table`, and migrate the database.',
                'A database-backed queue cannot store, retry, or inspect jobs correctly when its core tables do not exist.',
                self::DOCS_URL,
            );
        }

        $retryAfter = max((int) config('queue.connections.database.retry_after', 90), 1);
        $staleThreshold = max($retryAfter * 2, 300);
        $now = time();

        $staleReservedJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $now - $staleThreshold)
            ->count();

        if ($staleReservedJobs > 0) {
            return DoctorCheckResult::fail(
                sprintf('%d reserved database queue job(s) look stuck past the safe retry threshold', $staleReservedJobs),
                'Inspect the worker process, review job exceptions/timeouts, and consider restarting workers after fixing the underlying failure.',
                'Reserved jobs that never clear usually mean a dead worker, crashed process, or jobs timing out without recovery.',
                self::DOCS_URL,
            );
        }

        $stalePendingJobs = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<', $now - $staleThreshold)
            ->count();

        if ($stalePendingJobs > 0) {
            return DoctorCheckResult::fail(
                sprintf('%d database queue job(s) have been waiting too long without being processed', $stalePendingJobs),
                'Start or restore queue workers, then inspect why pending jobs are not being consumed.',
                'A growing backlog usually means your queue worker is down, misconfigured, or unable to keep up with production work.',
                self::DOCS_URL,
            );
        }

        $failedJobsCount = DB::table('failed_jobs')->count();
        if ($failedJobsCount > 0) {
            return DoctorCheckResult::warn(
                sprintf('%d failed queue job(s) recorded', $failedJobsCount),
                'Run `php artisan queue:failed`, fix the underlying exception, and retry or prune the failed jobs.',
                'Failed jobs often mean users are silently missing emails, notifications, exports, or other background work.',
                self::DOCS_URL,
            );
        }

        return null;
    }

    private function usesHorizon(): bool
    {
        $environments = config('horizon.environments');
        if (is_array($environments) && $environments !== []) {
            return true;
        }

        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR);

        return class_exists('Laravel\\Horizon\\Horizon')
            || file_exists($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'horizon.php');
    }

    private function findQueuedWorkUsage(): ?string
    {
        foreach ($this->scanDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFilesIn($directory) as $path) {
                $contents = file_get_contents($path);
                if ($contents === false) {
                    continue;
                }

                if (! preg_match('/ShouldQueue|::dispatch\s*\(|dispatch\s*\(new\s+|Bus::batch\s*\(/', $contents)) {
                    continue;
                }

                return $this->relativePath($path);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function scanDirectories(): array
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR);

        return [
            $root . DIRECTORY_SEPARATOR . 'app',
            $root . DIRECTORY_SEPARATOR . 'routes',
        ];
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $root)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
