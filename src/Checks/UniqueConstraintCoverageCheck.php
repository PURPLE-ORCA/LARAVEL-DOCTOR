<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class UniqueConstraintCoverageCheck implements DoctorCheck
{
    private const DOCS_URL = 'https://laravel.com/docs/migrations#available-index-types';

    /**
     * @var array<string, bool>
     */
    private array $tableExistsCache = [];

    /**
     * @var array<string, array<string, true>>
     */
    private array $columnCache = [];

    /**
     * @var array<string, list<array{name: string, columns: list<string>, unique: bool, primary: bool}>>
     */
    private array $indexCache = [];

    /**
     * @var array{fqcn: array<string, array{table: string}>, basename: array<string, list<string>>}|null
     */
    private ?array $modelMetadata = null;

    public function __construct(
        private readonly ?string $rootPath = null,
    ) {}

    public function name(): string
    {
        return 'Unique Constraint Coverage';
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
                            'Add a database unique index/constraint for that validated field, or stop claiming uniqueness only at the validator layer',
                            'Validator-only uniqueness breaks under concurrent writes and lets duplicate slugs, emails, or other keys leak into production data',
                            self::DOCS_URL,
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            return DoctorCheckResult::warn(
                'Unable to verify unique index coverage: ' . $e->getMessage(),
                'Ensure the database connection is configured and reachable before running this check',
                'Without live index introspection, Laravel Doctor cannot catch validator/database uniqueness drift before duplicate data lands in production',
                self::DOCS_URL,
            );
        }

        return DoctorCheckResult::pass('No obvious validator/database unique constraint drift found');
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

        $imports = $this->parseImports($contents);
        $namespace = $this->parseNamespace($contents);

        foreach ($this->validationFieldExpressions($contents) as $fieldExpression) {
            foreach ($this->extractUniqueRules($fieldExpression, $imports, $namespace) as $rule) {
                if (! $this->tableExists($rule['table'])) {
                    continue;
                }

                if (! $this->columnExists($rule['table'], $rule['column'])) {
                    continue;
                }

                if ($this->hasUniqueCoverage($rule['table'], $rule['column'])) {
                    continue;
                }

                return [
                    'message' => sprintf(
                        'Possible validator/database uniqueness drift in %s:%d — `%s.%s` is validated as unique but has no unique index coverage',
                        $this->relativePath($path),
                        $rule['line'],
                        $rule['table'],
                        $rule['column'],
                    ),
                ];
            }
        }

        return null;
    }

    /**
     * @return list<array{field: string, expression: string, line: int}>
     */
    private function validationFieldExpressions(string $contents): array
    {
        if (! preg_match_all('/(["\'])([A-Za-z0-9_.\-*]+)\1\s*=>\s*/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $expressions = [];

        foreach ($matches[2] as $index => $fieldMatch) {
            $field = $fieldMatch[0];
            $offset = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $segment = $this->extractDelimitedSegment($contents, $offset, [',']);

            if ($segment['code'] === '') {
                continue;
            }

            $expressions[] = [
                'field' => $field,
                'expression' => $segment['code'],
                'line' => 1 + substr_count(substr($contents, 0, (int) $matches[0][$index][1]), "\n"),
            ];
        }

        return $expressions;
    }

    /**
     * @param array{field: string, expression: string, line: int} $fieldExpression
     * @param array<string, string> $imports
     * @return list<array{table: string, column: string, line: int}>
     */
    private function extractUniqueRules(array $fieldExpression, array $imports, ?string $namespace): array
    {
        $rules = [];
        $field = $fieldExpression['field'];
        $expression = $fieldExpression['expression'];
        $baseLine = $fieldExpression['line'];

        if (preg_match_all('/(["\'])(unique:[^"\']+)\1/', $expression, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $match) {
                $parsed = $this->parseStringUniqueRule($field, $match[0]);
                if ($parsed === null) {
                    continue;
                }

                $rules[] = [
                    'table' => $parsed['table'],
                    'column' => $parsed['column'],
                    'line' => $baseLine + $this->lineDeltaForOffset($expression, $match[1]),
                ];
            }
        }

        $needle = 'Rule::unique(';
        $cursor = 0;
        while (($position = strpos($expression, $needle, $cursor)) !== false) {
            $segment = $this->extractDelimitedSegment($expression, $position, [',']);
            $cursor = $position + max($segment['length'], 1);

            if (preg_match('/->\s*where\s*\(/', $segment['code'])) {
                continue;
            }

            $parsed = $this->parseRuleUniqueExpression($field, $segment['code'], $imports, $namespace);
            if ($parsed === null) {
                continue;
            }

            $rules[] = [
                'table' => $parsed['table'],
                'column' => $parsed['column'],
                'line' => $baseLine + $this->lineDeltaForOffset($expression, $position),
            ];
        }

        return $rules;
    }

    /**
     * @return array{table: string, column: string}|null
     */
    private function parseStringUniqueRule(string $field, string $rule): ?array
    {
        if (! str_starts_with($rule, 'unique:')) {
            return null;
        }

        $parts = array_map('trim', explode(',', substr($rule, 7)));
        $table = $parts[0] ?? '';
        if ($table === '' || str_contains($table, '*') || str_contains($table, ' ')) {
            return null;
        }

        $column = $parts[1] ?? $this->defaultColumnForField($field);
        if ($column === null || str_contains($column, '*') || str_contains($column, '.')) {
            return null;
        }

        return [
            'table' => $table,
            'column' => $column,
        ];
    }

    /**
     * @param array<string, string> $imports
     * @return array{table: string, column: string}|null
     */
    private function parseRuleUniqueExpression(string $field, string $expression, array $imports, ?string $namespace): ?array
    {
        $openParen = strpos($expression, '(');
        if ($openParen === false) {
            return null;
        }

        $arguments = $this->extractDelimitedSegment($expression, $openParen + 1, [')']);
        $parts = $this->splitArguments($arguments['code']);
        if ($parts === []) {
            return null;
        }

        $table = $this->resolveRuleTargetToTable($parts[0], $imports, $namespace);
        if ($table === null || str_contains($table, '*') || str_contains($table, ' ')) {
            return null;
        }

        $column = isset($parts[1])
            ? $this->parseStringLiteral($parts[1])
            : $this->defaultColumnForField($field);

        if ($column === null || str_contains($column, '*') || str_contains($column, '.')) {
            return null;
        }

        return [
            'table' => $table,
            'column' => $column,
        ];
    }

    private function defaultColumnForField(string $field): ?string
    {
        if (str_contains($field, '.') || str_contains($field, '*')) {
            return null;
        }

        return $field;
    }

    /**
     * @param array<string, string> $imports
     */
    private function resolveRuleTargetToTable(string $target, array $imports, ?string $namespace): ?string
    {
        if ($literal = $this->parseStringLiteral($target)) {
            return $literal;
        }

        $target = trim($target);
        if (! str_ends_with($target, '::class')) {
            return null;
        }

        $candidate = substr($target, 0, -7);
        $model = $this->resolveModelInfo($candidate, $imports, $namespace);

        return $model['table'] ?? null;
    }

    private function parseStringLiteral(string $value): ?string
    {
        $value = trim($value);

        if (! preg_match('/^(["\'])(.*)\1$/s', $value, $matches)) {
            return null;
        }

        return trim($matches[2]);
    }

    /**
     * @return list<string>
     */
    private function splitArguments(string $arguments): array
    {
        $parts = [];
        $buffer = '';
        $stack = [];
        $quote = null;
        $escapeNext = false;
        $length = strlen($arguments);

        for ($index = 0; $index < $length; $index++) {
            $char = $arguments[$index];

            if ($quote !== null) {
                $buffer .= $char;

                if ($escapeNext) {
                    $escapeNext = false;
                    continue;
                }

                if ($char === '\\') {
                    $escapeNext = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === '\'') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if (in_array($char, ['(', '[', '{'], true)) {
                $stack[] = $char;
                $buffer .= $char;
                continue;
            }

            if (in_array($char, [')', ']', '}'], true)) {
                if ($stack !== []) {
                    array_pop($stack);
                }

                $buffer .= $char;
                continue;
            }

            if ($char === ',' && $stack === []) {
                $part = trim($buffer);
                if ($part !== '') {
                    $parts[] = $part;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $part = trim($buffer);
        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * @param list<string> $delimiters
     * @return array{code: string, length: int}
     */
    private function extractDelimitedSegment(string $source, int $offset, array $delimiters): array
    {
        $segment = '';
        $stack = [];
        $quote = null;
        $escapeNext = false;
        $length = strlen($source);
        $consumed = 0;

        for ($index = $offset; $index < $length; $index++) {
            $char = $source[$index];

            if ($quote !== null) {
                $segment .= $char;
                $consumed++;

                if ($escapeNext) {
                    $escapeNext = false;
                    continue;
                }

                if ($char === '\\') {
                    $escapeNext = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === '\'') {
                $quote = $char;
                $segment .= $char;
                $consumed++;
                continue;
            }

            if (in_array($char, ['(', '[', '{'], true)) {
                $stack[] = $char;
                $segment .= $char;
                $consumed++;
                continue;
            }

            if (in_array($char, [')', ']', '}'], true)) {
                if ($stack === [] && in_array($char, $delimiters, true)) {
                    break;
                }

                if ($stack === []) {
                    break;
                }

                array_pop($stack);
                $segment .= $char;
                $consumed++;
                continue;
            }

            if ($stack === [] && in_array($char, $delimiters, true)) {
                break;
            }

            $segment .= $char;
            $consumed++;
        }

        return [
            'code' => trim($segment),
            'length' => $consumed,
        ];
    }

    private function lineDeltaForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, max(0, $offset)), "\n");
    }

    private function tableExists(string $table): bool
    {
        return $this->tableExistsCache[$table] ??= Schema::hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        if (! isset($this->columnCache[$table])) {
            $columns = Schema::getColumnListing($table);
            $this->columnCache[$table] = [];

            foreach ($columns as $listedColumn) {
                $this->columnCache[$table][strtolower($listedColumn)] = true;
            }
        }

        return isset($this->columnCache[$table][strtolower($column)]);
    }

    private function hasUniqueCoverage(string $table, string $column): bool
    {
        $needle = strtolower($column);

        foreach ($this->indexesForTable($table) as $index) {
            if (! $index['unique'] && ! $index['primary']) {
                continue;
            }

            if (in_array($needle, $index['columns'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    private function indexesForTable(string $table): array
    {
        if (isset($this->indexCache[$table])) {
            return $this->indexCache[$table];
        }

        $indexes = [];
        foreach (Schema::getIndexes($table) as $index) {
            $columns = [];
            foreach ($index['columns'] ?? [] as $column) {
                $columns[] = strtolower($column);
            }

            $indexes[] = [
                'name' => strtolower((string) ($index['name'] ?? '')),
                'columns' => $columns,
                'unique' => (bool) ($index['unique'] ?? false),
                'primary' => (bool) ($index['primary'] ?? false),
            ];
        }

        return $this->indexCache[$table] = $indexes;
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
     * @return array{fqcn: string, table: string}|null
     */
    private function resolveModelInfo(string $candidate, array $imports, ?string $namespace): ?array
    {
        return $this->resolveModelInfoFromMap($this->modelMetadata(), $candidate, $imports, $namespace);
    }

    /**
     * @param array{fqcn: array<string, array{table: string}>, basename: array<string, list<string>>} $map
     * @param array<string, string> $imports
     * @return array{fqcn: string, table: string}|null
     */
    private function resolveModelInfoFromMap(array $map, string $candidate, array $imports, ?string $namespace): ?array
    {
        $candidate = ltrim($candidate, '\\');

        if (array_key_exists($candidate, $map['fqcn'])) {
            return [
                'fqcn' => $candidate,
                'table' => $map['fqcn'][$candidate]['table'],
            ];
        }

        if (array_key_exists($candidate, $imports) && array_key_exists($imports[$candidate], $map['fqcn'])) {
            return [
                'fqcn' => $imports[$candidate],
                'table' => $map['fqcn'][$imports[$candidate]]['table'],
            ];
        }

        if ($namespace !== null) {
            $fqcn = $namespace . '\\' . $candidate;
            if (array_key_exists($fqcn, $map['fqcn'])) {
                return [
                    'fqcn' => $fqcn,
                    'table' => $map['fqcn'][$fqcn]['table'],
                ];
            }
        }

        if (array_key_exists($candidate, $map['basename']) && count($map['basename'][$candidate]) === 1) {
            $fqcn = $map['basename'][$candidate][0];

            return [
                'fqcn' => $fqcn,
                'table' => $map['fqcn'][$fqcn]['table'],
            ];
        }

        return null;
    }

    /**
     * @return array{fqcn: array<string, array{table: string}>, basename: array<string, list<string>>}
     */
    private function modelMetadata(): array
    {
        if ($this->modelMetadata !== null) {
            return $this->modelMetadata;
        }

        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR);
        $appPath = $root . DIRECTORY_SEPARATOR . 'app';
        $map = [
            'fqcn' => [],
            'basename' => [],
        ];

        if (! is_dir($appPath)) {
            return $this->modelMetadata = $map;
        }

        foreach ($this->phpFilesIn($appPath) as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            if (! preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $contents, $classMatch)) {
                continue;
            }

            $class = $classMatch[1];
            $namespace = $this->parseNamespace($contents);
            if ($namespace === null) {
                continue;
            }

            $table = null;
            if (preg_match('/(?:protected|public)\s+(?:string\s+)?\$table\s*=\s*["\']([^"\']+)["\']\s*;/', $contents, $tableMatch)) {
                $table = $tableMatch[1];
            }

            $fqcn = $namespace . '\\' . $class;
            $map['fqcn'][$fqcn] = [
                'table' => $table ?? Str::snake(Str::pluralStudly($class)),
            ];
            $map['basename'][$class] ??= [];
            $map['basename'][$class][] = $fqcn;
        }

        return $this->modelMetadata = $map;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)))
            : str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
