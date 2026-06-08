<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class NPlusOneQueryCheck implements DoctorCheck
{
    private const RELATION_METHODS = [
        'belongsTo',
        'belongsToMany',
        'hasOne',
        'hasMany',
        'hasOneThrough',
        'hasManyThrough',
        'morphTo',
        'morphOne',
        'morphMany',
        'morphToMany',
        'morphedByMany',
    ];

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
            if (! preg_match('/foreach\s*\(\s*(.*?)\s+as\s+(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=>\s*)?(\$[A-Za-z_][A-Za-z0-9_]*)\s*\)/i', $line, $matches)) {
                continue;
            }

            $iterableExpr = trim($matches[1]);
            $itemVar = $matches[2];
            $context = $this->contextBefore($lines, $index, 30);

            if (! $this->looksLikeUncheckedQuerySource($context, $iterableExpr)) {
                continue;
            }

            $modelClass = $this->inferModelClass($context, $iterableExpr);
            if ($modelClass === null) {
                continue;
            }

            $body = $this->collectPhpLoopBody($lines, $index);
            $snippet = $this->findRelationChainSnippet($body, $itemVar, $modelClass);

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
            $context = $this->contextBefore($lines, $index, 30);

            if (! $this->looksLikeUncheckedBladeSource($context, $iterableExpr)) {
                continue;
            }

            $modelClass = $this->inferModelClass($context, $iterableExpr);
            if ($modelClass === null) {
                continue;
            }

            $body = $this->collectBladeLoopBody($lines, $index);
            $snippet = $this->findRelationChainSnippet($body, $itemVar, $modelClass);

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

    private function looksLikeUncheckedQuerySource(string $context, string $iterableExpr): bool
    {
        $haystack = $context . "\n" . $iterableExpr;

        if (preg_match('/\b(with|load|loadMissing)\s*\(/i', $haystack)) {
            return false;
        }

        return (bool) preg_match('/::(?:all|get|paginate|cursor|simplePaginate|first|chunk|lazy)\s*\(/i', $haystack)
            || (bool) preg_match('/::query\s*\(\)/i', $haystack)
            || (bool) preg_match('/::where\s*\(/i', $haystack);
    }

    private function looksLikeUncheckedBladeSource(string $context, string $iterableExpr): bool
    {
        $haystack = $context . "\n" . $iterableExpr;

        if (preg_match('/\b(with|load|loadMissing)\s*\(/i', $haystack)) {
            return false;
        }

        return (bool) preg_match('/::(?:all|get|paginate|cursor|simplePaginate|first|chunk|lazy)\s*\(/i', $haystack)
            || (bool) preg_match('/::query\s*\(\)/i', $haystack)
            || (bool) preg_match('/::where\s*\(/i', $haystack);
    }

    private function inferModelClass(string $context, string $iterableExpr): ?string
    {
        foreach ([$iterableExpr, $context] as $source) {
            if (preg_match('/(?:^|[^$])([A-Z][A-Za-z0-9_\\\\]*)::(?:query|all|get|paginate|cursor|simplePaginate|first|chunk|lazy|where|find|with|load|loadMissing)\b/i', $source, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function findRelationChainSnippet(string $body, string $itemVar, string $modelClass): ?string
    {
        $modelFile = $this->resolveModelFile($modelClass);
        if ($modelFile === null) {
            return null;
        }

        $modelContents = file_get_contents($modelFile);
        if ($modelContents === false) {
            return null;
        }

        if (! preg_match_all('/' . preg_quote($itemVar, '/') . '\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*->\s*[A-Za-z_][A-Za-z0-9_]*(?:\s*->\s*[A-Za-z_][A-Za-z0-9_]*)*/', $body, $matches, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($matches as $match) {
            $property = $match[1];

            if ($this->modelHasScalarAttribute($modelContents, $property)) {
                continue;
            }

            if ($this->modelHasRelationMethod($modelContents, $property)) {
                return trim($match[0]);
            }
        }

        return null;
    }

    private function modelHasScalarAttribute(string $contents, string $attribute): bool
    {
        $quotedAttribute = preg_quote($attribute, '/');

        if (preg_match('/\$casts\s*=\s*\[(?:.|\n)*?[\'\"]' . $quotedAttribute . '[\'\"]\s*=>/i', $contents)) {
            return true;
        }

        if (preg_match('/function\s+casts\s*\(\s*\)\s*:\s*array\s*\{(?:.|\n)*?[\'\"]' . $quotedAttribute . '[\'\"]\s*=>/i', $contents)) {
            return true;
        }

        if (preg_match('/\$dates\s*=\s*\[(?:.|\n)*?[\'\"]' . $quotedAttribute . '[\'\"]/i', $contents)) {
            return true;
        }

        if (preg_match('/function\s+get' . preg_quote($this->studly($attribute), '/') . 'Attribute\s*\(/i', $contents)) {
            return true;
        }

        if (preg_match('/function\s+' . preg_quote($this->camel($attribute), '/') . '\s*\([^)]*\)\s*:\s*Attribute\b/i', $contents)) {
            return true;
        }

        return false;
    }

    private function modelHasRelationMethod(string $contents, string $relationName): bool
    {
        $methodBlock = $this->extractMethodBlock($contents, $relationName);
        if ($methodBlock === null) {
            return false;
        }

        if (preg_match('/return\s+\$this\s*->\s*(' . implode('|', self::RELATION_METHODS) . ')\s*\(/i', $methodBlock)) {
            return true;
        }

        if (preg_match('/:\s*(?:\\\\?Illuminate\\\\Database\\\\Eloquent\\\\Relations\\\\)?[A-Za-z_\\\\]*Relation\b/i', $methodBlock)) {
            return true;
        }

        if (preg_match('/@return\s+[^
]*(?:\\\\?Illuminate\\\\Database\\\\Eloquent\\\\Relations\\\\)?[A-Za-z_\\\\]*Relation\b/i', $methodBlock)) {
            return true;
        }

        return false;
    }

    private function extractMethodBlock(string $contents, string $methodName): ?string
    {
        if (! preg_match('/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{/i', $contents, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $match[0][1];
        $braceStart = strpos($contents, '{', $start);
        if ($braceStart === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($contents);

        for ($i = $braceStart; $i < $length; $i++) {
            if ($contents[$i] === '{') {
                $depth++;
                continue;
            }

            if ($contents[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($contents, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function resolveModelFile(string $modelClass): ?string
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR);
        $shortName = basename(str_replace('\\', '/', $modelClass)) . '.php';
        $relativeClassPath = str_replace('\\', DIRECTORY_SEPARATOR, preg_replace('/^App\\\\/i', '', $modelClass)) . '.php';

        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $relativeClassPath,
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $shortName,
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $shortName,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $appDirectory = $root . DIRECTORY_SEPARATOR . 'app';
        if (! is_dir($appDirectory)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDirectory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getFilename() === $shortName) {
                return $fileInfo->getPathname();
            }
        }

        return null;
    }

    private function studly(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);

        return str_replace(' ', '', $value);
    }

    private function camel(string $value): string
    {
        $studly = $this->studly($value);

        return lcfirst($studly);
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR) : $path;
    }
}
