<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Commands;

use Sahraoui\Doctor\Checks\AppKeyCheck;
use Sahraoui\Doctor\Checks\CacheStatusCheck;
use Sahraoui\Doctor\Checks\DebugModeCheck;
use Sahraoui\Doctor\Checks\PhpVersionCheck;
use Sahraoui\Doctor\Checks\SecurityAdvisoriesCheck;
use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Output\ConsoleFormatter;
use Sahraoui\Doctor\Scoring\ScoreCalculator;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'doctor
        {--json : Output results as JSON}
        {--category= : Run checks for a specific category only}';

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
        $results = [];
        foreach ($checks as $check) {
            $results[] = [
                'check' => $check,
                'result' => $check->run(),
            ];
        }

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

        $formatter->render($grouped, $score, $breakdown);

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
            new PhpVersionCheck,
            new AppKeyCheck,
            new DebugModeCheck,
            new CacheStatusCheck,
            new SecurityAdvisoriesCheck,
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
