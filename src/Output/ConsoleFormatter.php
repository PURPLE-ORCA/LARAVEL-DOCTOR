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
    public function render(array $groupedResults, int $score, array $breakdown, float $elapsedSeconds = 0): void
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
            $this->output->writeln("  <options=bold>{$this->titleCase($category)}</options=bold>");

            foreach ($results as $item) {
                $check = $item['check'];
                $result = $item['result'];

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
        $scoreColor = $score >= 80 ? 'green' : ($score >= 60 ? 'yellow' : 'red');
        $this->output->writeln("  <options=bold>Score: <fg={$scoreColor}>{$score}/100</></options=bold>");

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

    private function titleCase(string $value): string
    {
        return ucfirst(str_replace('-', ' ', $value));
    }
}
