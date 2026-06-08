<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Commands;

use PurpleOrca\Doctor\Checks\AppEnvCheck;
use PurpleOrca\Doctor\Checks\AppKeyCheck;
use PurpleOrca\Doctor\Checks\AppUrlCheck;
use PurpleOrca\Doctor\Checks\CacheDriverCheck;
use PurpleOrca\Doctor\Checks\CacheStatusCheck;
use PurpleOrca\Doctor\Checks\DatabaseConnectionCheck;
use PurpleOrca\Doctor\Checks\DebugModeCheck;
use PurpleOrca\Doctor\Checks\EnvFileCheck;
use PurpleOrca\Doctor\Checks\MailMailerCheck;
use PurpleOrca\Doctor\Checks\MaintenanceModeCheck;
use PurpleOrca\Doctor\Checks\PhpVersionCheck;
use PurpleOrca\Doctor\Checks\QueueConnectionCheck;
use PurpleOrca\Doctor\Checks\SecurityAdvisoriesCheck;
use PurpleOrca\Doctor\Checks\SessionDriverCheck;
use PurpleOrca\Doctor\Checks\StorageLinkCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Output\ConsoleFormatter;
use PurpleOrca\Doctor\Scoring\ScoreCalculator;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'doctor
        {--json : Output results as JSON}
        {--category= : Run checks for a specific category only}
        {--all : Show all checks including passing ones}';

    protected $description = 'Run health checks on your Laravel application';

    public function handle(): int
    {
        $scorer = new ScoreCalculator;
        $formatter = new ConsoleFormatter($this->output);

        // Build the check list
        $checks = $this->buildChecks();

        // Filter by category if specified
        if ($category = $this->option('category')) {
            $checks = array_filter($checks, fn (DoctorCheck $check) => $check->category() === $category);

            if ($checks === []) {
                $this->error("No checks found for category: {$category}");

                return static::FAILURE;
            }
        }

        // Run all checks
        $start = microtime(true);
        $results = [];
        foreach ($checks as $check) {
            $results[] = [
                'check' => $check,
                'result' => $check->run(),
            ];
        }
        $elapsed = microtime(true) - $start;

        // Calculate score
        $resultObjects = array_map(fn (array $item) => $item['result'], $results);
        $score = $scorer->calculate($resultObjects);
        $breakdown = $scorer->breakdown($resultObjects);

        // JSON output
        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'score' => $score,
                'breakdown' => $breakdown,
                'checks' => array_map(fn (array $item) => [
                    'name' => $item['check']->name(),
                    'category' => $item['check']->category(),
                    'status' => $item['result']->status->value,
                    'message' => $item['result']->message,
                    'advice' => $item['result']->advice,
                ], $results),
            ], JSON_PRETTY_PRINT));

            return $score >= 60 ? static::SUCCESS : static::FAILURE;
        }

        // Group by category for display
        $grouped = [];
        foreach ($results as $item) {
            $category = $item['check']->category();
            $grouped[$category][] = $item;
        }

        $formatter->render($grouped, $score, $breakdown, $elapsed, $this->option('all'));

        // Hidden passes hint
        $hiddenPasses = $breakdown['pass'];
        if (! $this->option('all') && $hiddenPasses > 0) {
            $this->output->writeln("  <comment>+{$hiddenPasses} passed — run with --all to see all checks</comment>");
            $this->output->writeln('');
        }

        // Exit code based on failures
        if ($breakdown['fail'] > 0) {
            return static::FAILURE;
        }

        return static::SUCCESS;
    }

    /**
     * @return list<DoctorCheck>
     */
    private function buildChecks(): array
    {
        $checks = [
            // Environment
            new PhpVersionCheck,
            new AppKeyCheck,
            new AppEnvCheck,
            new AppUrlCheck,
            new EnvFileCheck,
            new SessionDriverCheck,
            new CacheDriverCheck,
            new QueueConnectionCheck,
            new MailMailerCheck,
            new MaintenanceModeCheck,

            // Security
            new DebugModeCheck,
            new SecurityAdvisoriesCheck,

            // Performance
            new CacheStatusCheck,
            new RouteCacheCheck,
            new ViewCacheCheck,
            new OpCacheCheck,
            new StorageLinkCheck,

            // Infrastructure
            new DatabaseConnectionCheck,
        ];

        // Register any custom checks from config
        $customChecks = config('doctor.checks', []);

        foreach ($customChecks as $checkClass) {
            if (class_exists($checkClass)) {
                $checks[] = new $checkClass;
            }
        }

        return $checks;
    }
}
