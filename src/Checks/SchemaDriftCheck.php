<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class SchemaDriftCheck implements DoctorCheck
{
    private const DOCS_URL = 'https://laravel.com/docs/migrations';

    /**
     * @var array<string, bool>
     */
    private array $tableExistsCache = [];

    /**
     * @var array<string, array<string, true>>
     */
    private array $columnCache = [];

    /**
     * @var array{fqcn: array<string, string>, basename: array<string, list<string>>}|null
     */
    private ?array $modelTableMap = null;

    public function __construct(
        private readonly ?string $rootPath = null,
    ) {}

    public function name(): string
    {
        return 'Schema Drift';
    }

    public function category(): string
    {
        return 'infrastructure';
    }

    public function run(): DoctorCheckResult
    {
        try {
            foreach ($this->scanDirectories() as $directory) {
                if (! is_dir($directory)) {
                    continue;
                }

                foreach ($this->phpFilesIn($directory) as $file) {
                    $issue = $this->scanPhpFile($file);

                    if ($issue !== null) {
                        return DoctorCheckResult::fail(
                            $issue['message'],
                            'Run the missing migration before deploying this code, or remove the stale table/column reference',
                            'Schema drift causes SQL exceptions, failed jobs, and production 500s as soon as this code path executes',
                            self::DOCS_URL,
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            return DoctorCheckResult::warn(
                'Unable to inspect database schema for drift: ' . $e->getMessage(),
                'Ensure the database connection is configured and reachable before running this check',
                'Without live schema introspection, Laravel Doctor cannot catch code/database mismatches before they blow up at runtime',
                self::DOCS_URL,
            );
        }

        return DoctorCheckResult::pass('No obvious schema drift patterns found');
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
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
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

    /**
     * @return array{message: string}|null
     */
    private function scanPhpFile(string $path): ?array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $namespace = $this->parseNamespace($contents);
        $imports = $this->parseImports($contents);
        $builders = [];

        foreach ($this->statements($contents) as $statement) {
            $source = $this->detectInlineSource($statement['code'], $imports, $namespace);

            if ($source !== null) {
                $issue = $this->detectMissingSchema($source['table'], $statement['code'], $path, $statement['line']);
                if ($issue !== null) {
                    return $issue;
                }

                if ($source['assignVar'] !== null && ! $this->containsTerminalMethod($statement['code'])) {
                    $builders[$source['assignVar']] = $source['table'];
                }

                continue;
            }

            $tracked = $this->detectTrackedBuilder($statement['code'], $builders);
            if ($tracked === null) {
                continue;
            }

            $issue = $this->detectMissingSchema($tracked['table'], $statement['code'], $path, $statement['line']);
            if ($issue !== null) {
                return $issue;
            }

            if ($tracked['assignVar'] !== null) {
                $builders[$tracked['assignVar']] = $tracked['table'];
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, line: int}>
     */
    private function statements(string $contents): array
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $statements = [];
        $buffer = '';
        $startLine = 1;

        foreach ($lines as $index => $line) {
            if ($buffer === '') {
                $startLine = $index + 1;
            }

            $buffer .= $line . "\n";

            if (! str_contains($line, ';')) {
                continue;
            }

            $parts = explode(';', $buffer);
            $lastIndex = count($parts) - 1;
            $lineCursor = $startLine;

            foreach ($parts as $partIndex => $part) {
                if ($partIndex === $lastIndex) {
                    $buffer = $part;
                    $startLine = $lineCursor + substr_count($part, "\n");
                    continue;
                }

                $statement = trim($part);
                if ($statement !== '') {
                    $statements[] = [
                        'code' => $statement . ';',
                        'line' => $lineCursor,
                    ];
                }

                $lineCursor += substr_count($part, "\n");
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = [
                'code' => $tail,
                'line' => $startLine,
            ];
        }

        return $statements;
    }

    private function parseNamespace(string $contents): ?string
    {
        if (! preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @return array<string, string>
     */
    private function parseImports(string $contents): array
    {
        preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

        $imports = [];
        foreach ($matches[1] ?? [] as $rawImport) {
            $rawImport = trim($rawImport);
            if (str_starts_with($rawImport, 'function ') || str_starts_with($rawImport, 'const ')) {
                continue;
            }

            if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $rawImport, $aliased)) {
                $imports[$aliased[2]] = trim($aliased[1], '\\');
                continue;
            }

            $fqcn = trim($rawImport, '\\');
            $parts = explode('\\', $fqcn);
            $imports[end($parts)] = $fqcn;
        }

        return $imports;
    }

    /**
     * @param array<string, string> $imports
     * @return array{table: string, assignVar: string|null}|null
     */
    private function detectInlineSource(string $statement, array $imports, ?string $namespace): ?array
    {
        $assignVar = null;
        if (preg_match('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=/', $statement, $assignment)) {
            $assignVar = $assignment[1];
        }

        if (preg_match('/(?:DB::|db\(\)->)table\(\s*["\']([A-Za-z_][A-Za-z0-9_]*)["\']\s*\)/', $statement, $tableMatch)) {
            return [
                'table' => $tableMatch[1],
                'assignVar' => $assignVar,
            ];
        }

        if (! preg_match('/(?<!->)((?:\\\\)?[A-Z][A-Za-z0-9_\\\\]*)::(?:query|where|orWhere|whereIn|whereNotIn|orderBy|groupBy|having|select|addSelect|pluck|value|firstWhere|update|insert)\s*\(/', $statement, $classMatch)) {
            return null;
        }

        $table = $this->resolveModelTable($classMatch[1], $imports, $namespace);
        if ($table === null) {
            return null;
        }

        return [
            'table' => $table,
            'assignVar' => $assignVar,
        ];
    }

    /**
     * @param array<string, string> $builders
     * @return array{table: string, assignVar: string|null}|null
     */
    private function detectTrackedBuilder(string $statement, array $builders): ?array
    {
        if (! preg_match('/^\s*(?:(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*)?(\$[A-Za-z_][A-Za-z0-9_]*)->/', $statement, $matches)) {
            return null;
        }

        $sourceVar = $matches[2];
        if (! array_key_exists($sourceVar, $builders)) {
            return null;
        }

        return [
            'table' => $builders[$sourceVar],
            'assignVar' => $matches[1] ?? null,
        ];
    }

    private function containsTerminalMethod(string $statement): bool
    {
        return (bool) preg_match('/->\s*(get|first|find|paginate|simplePaginate|cursor|lazy|chunk|count|exists|doesntExist|insert|update|delete|create|pluck|value)\s*\(/', $statement);
    }

    /**
     * @return array{message: string}|null
     */
    private function detectMissingSchema(string $table, string $statement, string $path, int $line): ?array
    {
        if (! $this->tableExists($table)) {
            return [
                'message' => sprintf(
                    'Possible schema drift detected in %s:%d — code queries missing table `%s`',
                    $this->relativePath($path),
                    $line,
                    $table,
                ),
            ];
        }

        foreach ($this->extractColumns($statement, $table) as $column) {
            if (! $this->columnExists($table, $column)) {
                return [
                    'message' => sprintf(
                        'Possible schema drift detected in %s:%d — code references missing column `%s.%s`',
                        $this->relativePath($path),
                        $line,
                        $table,
                        $column,
                    ),
                ];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractColumns(string $statement, string $table): array
    {
        $columns = [];

        $singleColumnPatterns = [
            '/(?:->|::)(?:where|orWhere|whereIn|whereNotIn|firstWhere|having)\s*\(\s*["\']([^"\']+)["\']/',
            '/(?:->|::)(?:orderBy|groupBy|pluck|value)\s*\(\s*["\']([^"\']+)["\']/',
        ];

        foreach ($singleColumnPatterns as $pattern) {
            preg_match_all($pattern, $statement, $matches);
            foreach ($matches[1] ?? [] as $rawColumn) {
                $column = $this->normalizeColumn($table, $rawColumn);
                if ($column !== null) {
                    $columns[] = $column;
                }
            }
        }

        if (preg_match_all('/(?:->|::)(?:select|addSelect)\s*\((.*?)\)/s', $statement, $selectMatches)) {
            foreach ($selectMatches[1] as $argumentList) {
                preg_match_all('/["\']([^"\']+)["\']/', $argumentList, $quotedColumns);
                foreach ($quotedColumns[1] ?? [] as $rawColumn) {
                    $column = $this->normalizeColumn($table, $rawColumn);
                    if ($column !== null) {
                        $columns[] = $column;
                    }
                }
            }
        }

        if (preg_match_all('/(?:->|::)(?:update|insert)\s*\(\s*\[(.*?)\]\s*\)/s', $statement, $arrayMatches)) {
            foreach ($arrayMatches[1] as $arrayBody) {
                preg_match_all('/["\']([^"\']+)["\']\s*=>/', $arrayBody, $arrayKeys);
                foreach ($arrayKeys[1] ?? [] as $rawColumn) {
                    $column = $this->normalizeColumn($table, $rawColumn);
                    if ($column !== null) {
                        $columns[] = $column;
                    }
                }
            }
        }

        return array_values(array_unique($columns));
    }

    private function normalizeColumn(string $table, string $column): ?string
    {
        $column = trim($column);
        if ($column === '' || $column === '*') {
            return null;
        }

        if (preg_match('/\s+as\s+/i', $column)) {
            $column = preg_split('/\s+as\s+/i', $column)[0] ?? $column;
        }

        if (str_contains($column, '->')) {
            $column = explode('->', $column, 2)[0];
        }

        if (str_contains($column, '.')) {
            [$prefix, $suffix] = explode('.', $column, 2);
            if ($prefix !== $table) {
                return null;
            }

            $column = $suffix;
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return null;
        }

        return $column;
    }

    private function tableExists(string $table): bool
    {
        if (! array_key_exists($table, $this->tableExistsCache)) {
            $this->tableExistsCache[$table] = Schema::hasTable($table);
        }

        return $this->tableExistsCache[$table];
    }

    private function columnExists(string $table, string $column): bool
    {
        if (! array_key_exists($table, $this->columnCache)) {
            $columns = Schema::getColumnListing($table);
            $this->columnCache[$table] = array_fill_keys($columns, true);
        }

        return array_key_exists($column, $this->columnCache[$table]);
    }

    private function resolveModelTable(string $candidate, array $imports, ?string $namespace): ?string
    {
        $map = $this->modelTableMap();
        $candidate = ltrim($candidate, '\\');

        if (array_key_exists($candidate, $map['fqcn'])) {
            return $map['fqcn'][$candidate];
        }

        if (array_key_exists($candidate, $imports) && array_key_exists($imports[$candidate], $map['fqcn'])) {
            return $map['fqcn'][$imports[$candidate]];
        }

        if ($namespace !== null) {
            $fqcn = $namespace . '\\' . $candidate;
            if (array_key_exists($fqcn, $map['fqcn'])) {
                return $map['fqcn'][$fqcn];
            }
        }

        if (array_key_exists($candidate, $map['basename']) && count($map['basename'][$candidate]) === 1) {
            return $map['fqcn'][$map['basename'][$candidate][0]];
        }

        return null;
    }

    /**
     * @return array{fqcn: array<string, string>, basename: array<string, list<string>>}
     */
    private function modelTableMap(): array
    {
        if ($this->modelTableMap !== null) {
            return $this->modelTableMap;
        }

        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR);
        $appPath = $root . DIRECTORY_SEPARATOR . 'app';
        $map = [
            'fqcn' => [],
            'basename' => [],
        ];

        if (! is_dir($appPath)) {
            $this->modelTableMap = $map;

            return $this->modelTableMap;
        }

        foreach ($this->phpFilesIn($appPath) as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            if (! preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+(?:(?:\\\\)?[A-Za-z_\\\\]+\\\\)?Model\b/', $contents, $classMatch)) {
                continue;
            }

            $class = $classMatch[1];
            $namespace = $this->parseNamespace($contents);
            if ($namespace === null) {
                continue;
            }

            $table = null;
            if (preg_match('/protected\s+(?:string\s+)?\$table\s*=\s*["\']([^"\']+)["\']\s*;/', $contents, $tableMatch)) {
                $table = $tableMatch[1];
            }

            $fqcn = $namespace . '\\' . $class;
            $map['fqcn'][$fqcn] = $table ?? Str::snake(Str::pluralStudly($class));
            $map['basename'][$class] ??= [];
            $map['basename'][$class][] = $fqcn;
        }

        $this->modelTableMap = $map;

        return $this->modelTableMap;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)))
            : str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
