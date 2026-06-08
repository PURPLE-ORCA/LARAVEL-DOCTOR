<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class NPlusOneQueryCheck implements DoctorCheck
{
    public function __construct(
        private readonly ?string $rootPath = null,
    ) {}

    public function name(): string
    {
        return 'N+1 Queries';
    }

    public function category(): string
    {
        return 'performance';
    }

    public function run(): DoctorCheckResult
    {
        foreach ($this->scanDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFilesIn($directory) as $file) {
                $issue = str_ends_with($file, '.blade.php')
                    ? $this->scanBladeFile($file)
                    : $this->scanPhpFile($file);

                if ($issue !== null) {
                    return DoctorCheckResult::fail(
                        "Possible N+1 query detected in {$issue['file']}:{$issue['line']} — {$issue['snippet']}",
                        'Eager load the relation before the loop with with(), load(), or loadMissing()',
                        'This pattern can trigger one extra query per loop iteration and quietly crush throughput',
                        'https://laravel.com/docs/eloquent-relationships#eager-loading',
                    );
                }
            }
        }

        return DoctorCheckResult::pass('No obvious N+1 query patterns found');
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
            $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views',
        ];
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();

            if (str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array{file: string, line: int, snippet: string}|null
     */
    private function scanPhpFile(string $path): ?array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $index => $line) {
            if (! preg_match('/foreach\s*\(\s*(\$[A-Za-z_][A-Za-z0-9_]*)\s+as\s+(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=>\s*)?(\$[A-Za-z_][A-Za-z0-9_]*)/i', $line, $matches)) {
                continue;
            }

            $iterableVar = $matches[1];
            $itemVar = $matches[2];
            $context = $this->contextBefore($lines, $index, 20);

            if (! $this->looksLikeUncheckedQuerySource($context, $iterableVar)) {
                continue;
            }

            $body = $this->collectPhpLoopBody($lines, $index);
            $snippet = $this->findRelationChainSnippet($body, $itemVar);

            if ($snippet !== null) {
                return [
                    'file' => $this->relativePath($path),
                    'line' => $index + 1,
                    'snippet' => $snippet,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{file: string, line: int, snippet: string}|null
     */
    private function scanBladeFile(string $path): ?array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $index => $line) {
            if (! preg_match('/@foreach\s*\(\s*(.*?)\s+as\s+(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=>\s*)?(\$[A-Za-z_][A-Za-z0-9_]*)\s*\)/i', $line, $matches)) {
                continue;
            }

            $iterableExpr = trim($matches[1]);
            $itemVar = $matches[2];
            $context = $this->contextBefore($lines, $index, 20);

            // Blade is conservative: only flag if we can see a query source or inline eager-load omission.
            if (! $this->looksLikeUncheckedBladeSource($context, $iterableExpr)) {
                continue;
            }

            $body = $this->collectBladeLoopBody($lines, $index);
            $snippet = $this->findRelationChainSnippet($body, $itemVar);

            if ($snippet !== null) {
                return [
                    'file' => $this->relativePath($path),
                    'line' => $index + 1,
                    'snippet' => $snippet,
                ];
            }
        }

        return null;
    }

    private function collectPhpLoopBody(array $lines, int $startIndex): string
    {
        $buffer = [];
        $braceLevel = 0;
        $started = false;

        for ($i = $startIndex; $i < count($lines); $i++) {
            $current = $lines[$i];
            $buffer[] = $current;

            $braceLevel += substr_count($current, '{');
            $braceLevel -= substr_count($current, '}');

            if (str_contains($current, '{')) {
                $started = true;
            }

            if ($started && $braceLevel <= 0) {
                break;
            }

            if (! $started && preg_match('/:\s*$/', trim($current))) {
                // Alternative syntax: stop at endforeach;
                for ($j = $i + 1; $j < count($lines); $j++) {
                    $buffer[] = $lines[$j];
                    if (preg_match('/^\s*endforeach\s*;\s*$/', $lines[$j])) {
                        break 2;
                    }
                }
            }
        }

        return implode("\n", $buffer);
    }

    private function collectBladeLoopBody(array $lines, int $startIndex): string
    {
        $buffer = [];

        for ($i = $startIndex; $i < count($lines); $i++) {
            $current = $lines[$i];
            $buffer[] = $current;

            if (preg_match('/@endforeach\b/i', $current)) {
                break;
            }
        }

        return implode("\n", $buffer);
    }

    private function contextBefore(array $lines, int $index, int $window): string
    {
        $start = max(0, $index - $window);

        return implode("\n", array_slice($lines, $start, $index - $start));
    }

    private function looksLikeUncheckedQuerySource(string $context, string $iterableVar): bool
    {
        if (! str_contains($context, $iterableVar)) {
            return false;
        }

        if (preg_match('/' . preg_quote($iterableVar, '/') . '\s*=\s*.*?(with|load|loadMissing)\s*\(/is', $context)) {
            return false;
        }

        return (bool) preg_match('/' . preg_quote($iterableVar, '/') . '\s*=\s*.*?(::all|->get|::get|paginate|cursor|simplePaginate)\s*\(/is', $context);
    }

    private function looksLikeUncheckedBladeSource(string $context, string $iterableExpr): bool
    {
        if (preg_match('/(with|load|loadMissing)\s*\(/i', $context)) {
            return false;
        }

        if (preg_match('/@php\s*\(?\s*\$\w+\s*=\s*.*?(::all|->get|::get|paginate|cursor|simplePaginate)\s*\(/is', $context)) {
            return true;
        }

        return (bool) preg_match('/' . preg_quote($iterableExpr, '/') . '.*?(::all|->get|::get|paginate|cursor|simplePaginate)\s*\(/is', $context);
    }

    private function findRelationChainSnippet(string $body, string $itemVar): ?string
    {
        if (preg_match('/' . preg_quote($itemVar, '/') . '\s*->\s*[A-Za-z_][A-Za-z0-9_]*\s*->\s*[A-Za-z_][A-Za-z0-9_]*/', $body, $matches)) {
            return trim($matches[0]);
        }

        return null;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR) : $path;
    }
}
