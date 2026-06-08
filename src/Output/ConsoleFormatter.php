<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Output;

use PurpleOrca\Doctor\Enums\Status;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleFormatter
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    /**
     * @param array<string, list<array{check: \PurpleOrca\Doctor\Contracts\DoctorCheck, result: \PurpleOrca\Doctor\Contracts\DoctorCheckResult}>> $groupedResults
     */
    public function render(array $groupedResults, int $score, array $breakdown, float $elapsedSeconds = 0, bool $verbose = false): void
    {
        $totalChecks = $breakdown['pass'] + $breakdown['warn'] + $breakdown['fail'];
        $totalIssues = $breakdown['warn'] + $breakdown['fail'];

        $this->output->writeln('');
        $this->output->writeln('  <options=bold>🔬 Laravel Doctor</options=bold>');
        $this->output->writeln('');

        // Scan metadata
        $elapsed = $elapsedSeconds > 0 ? sprintf(' in %.1fs', $elapsedSeconds) : '';
        $this->output->writeln("  <fg=green>✓</> Scanned {$totalChecks} checks{$elapsed}");
        $this->output->writeln('');

        // Issue summary
        if ($totalIssues > 0) {
            $this->renderIssueSummary($groupedResults);
            $this->output->writeln('');
        }

        foreach ($groupedResults as $category => $results) {
            $hasVisible = $verbose || $this->categoryHasIssues($results);
            if (! $hasVisible) {
                continue;
            }

            $this->output->writeln("  <options=bold>{$this->titleCase($category)}</options=bold>");

            foreach ($results as $item) {
                $result = $item['result'];

                if (! $verbose && $result->status === Status::Pass) {
                    continue;
                }

                $icon = match ($result->status) {
                    Status::Pass => '✓',
                    Status::Warn => '⚠',
                    Status::Fail => '✗',
                };
                $color = match ($result->status) {
                    Status::Pass => 'green',
                    Status::Warn => 'yellow',
                    Status::Fail => 'red',
                };

                $line = "    <fg={$color}>{$icon}</> {$result->message}";
                $this->output->writeln($line);

                if ($result->advice !== null) {
                    $this->output->writeln("      <comment>→ {$result->advice}</comment>");
                }
            }

            $this->output->writeln('');
        }

        // Score
        $scoreLabel = match (true) {
            $score >= 90 => 'Excellent',
            $score >= 80 => 'Good',
            $score >= 60 => 'Needs work',
            default => 'Critical',
        };
        $scoreColor = match (true) {
            $score >= 80 => 'green',
            $score >= 60 => 'yellow',
            default => 'red',
        };
        $barColor = match (true) {
            $score >= 80 => 'green',
            $score >= 60 => 'yellow',
            default => 'red',
        };

        $this->output->writeln("  <options=bold>Score: <fg={$scoreColor}>{$score}/100 {$scoreLabel}</></options=bold>");
        $this->output->writeln('  ' . $this->renderProgressBar($score, $barColor));
        $this->output->writeln('');

        $parts = [];
        if ($breakdown['fail'] > 0) {
            $parts[] = "<fg=red>{$breakdown['fail']} errors</>";
        }
        if ($breakdown['warn'] > 0) {
            $parts[] = "<fg=yellow>{$breakdown['warn']} warnings</>";
        }
        if ($breakdown['pass'] > 0) {
            $parts[] = "<fg=green>{$breakdown['pass']} passed</>";
        }

        if ($parts !== []) {
            $this->output->writeln('  ' . implode(' │ ', $parts));
        }

        $this->output->writeln('');
    }

    /**
     * @param array<string, list<array{check: \PurpleOrca\Doctor\Contracts\DoctorCheck, result: \PurpleOrca\Doctor\Contracts\DoctorCheckResult}>> $groupedResults
     */
    private function renderIssueSummary(array $groupedResults): void
    {
        $summary = [];
        foreach ($groupedResults as $category => $results) {
            $errors = 0;
            $warnings = 0;
            foreach ($results as $item) {
                match ($item['result']->status) {
                    Status::Fail => $errors++,
                    Status::Warn => $warnings++,
                    Status::Pass => null,
                };
            }
            if ($errors > 0 || $warnings > 0) {
                $parts = [];
                if ($errors > 0) {
                    $parts[] = "<fg=red>{$errors} error" . ($errors > 1 ? 's' : '') . '</>';
                }
                if ($warnings > 0) {
                    $parts[] = "<fg=yellow>{$warnings} warning" . ($warnings > 1 ? 's' : '') . '</>';
                }
                $summary[] = "  <fg=cyan>{$this->titleCase($category)}</> > " . implode(', ', $parts);
            }
        }

        if ($summary !== []) {
            foreach ($summary as $line) {
                $this->output->writeln($line);
            }
        }
    }

    private function renderProgressBar(int $score, string $color): string
    {
        $width = 30;
        $filled = (int) round($score / 100 * $width);
        $empty = $width - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        return "<fg={$color}>{$bar}</>";
    }

    /**
     * @param list<array{check: \PurpleOrca\Doctor\Contracts\DoctorCheck, result: \PurpleOrca\Doctor\Contracts\DoctorCheckResult}> $results
     */
    private function categoryHasIssues(array $results): bool
    {
        foreach ($results as $item) {
            if ($item['result']->status !== Status::Pass) {
                return true;
            }
        }

        return false;
    }

    private function titleCase(string $value): string
    {
        return ucfirst(str_replace('-', ' ', $value));
    }
}
