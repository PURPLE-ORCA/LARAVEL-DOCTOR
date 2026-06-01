<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Output;

use Sahraoui\Doctor\Enums\Status;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleFormatter
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    /**
     * @param array<string, list<array{check: \Sahraoui\Doctor\Contracts\DoctorCheck, result: \Sahraoui\Doctor\Contracts\DoctorCheckResult}>> $groupedResults
     */
    public function render(array $groupedResults, int $score, array $breakdown): void
    {
        $this->output->writeln('');
        $this->output->writeln('  <bold>🔬 Laravel Doctor</bold>');
        $this->output->writeln('');

        foreach ($groupedResults as $category => $results) {
            $this->output->writeln("  <bold>{$this->titleCase($category)}</bold>");

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

                $line = "    <{$color}>{$icon}</{$color}> {$result->message}";
                $this->output->writeln($line);

                if ($result->advice !== null) {
                    $this->output->writeln("      <comment>→ {$result->advice}</comment>");
                }
            }

            $this->output->writeln('');
        }

        // Score
        $scoreColor = $score >= 80 ? 'green' : ($score >= 60 ? 'yellow' : 'red');
        $this->output->writeln("  <bold>Score: <{$scoreColor}>{$score}/100</{$scoreColor}></bold>");

        $parts = [];
        if ($breakdown['fail'] > 0) {
            $parts[] = "<red>{$breakdown['fail']} errors</red>";
        }
        if ($breakdown['warn'] > 0) {
            $parts[] = "<yellow>{$breakdown['warn']} warnings</yellow>";
        }
        if ($breakdown['pass'] > 0) {
            $parts[] = "<green>{$breakdown['pass']} passed</green>";
        }

        if ($parts !== []) {
            $this->output->writeln('  ' . implode(' │ ', $parts));
        }

        $this->output->writeln('');
    }

    private function titleCase(string $value): string
    {
        return ucfirst(str_replace('-', ' ', $value));
    }
}
