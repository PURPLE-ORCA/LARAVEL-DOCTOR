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
     * @var array{fqcn: array<string, array{table: string, relations: array<string, string>}>, basename: array<string, list<string>>}|null
     */
    private ?array $modelMetadata = null;

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
            $tracked = $this->detectTrackedBuilder($statement['code'], $builders);
            $rootSource = $tracked === null
                ? $this->detectRootInlineSource($statement['code'], $imports, $namespace)
                : null;

            $nestedSources = $this->nestedInlineSources(
                $statement['code'],
                $imports,
                $namespace,
                $rootSource['offset'] ?? null,
            );

            foreach ($nestedSources as $nestedSource) {
                $issue = $this->detectMissingSchema(
                    $nestedSource['table'],
                    $this->extractExpressionFromOffset($statement['code'], $nestedSource['offset']),
                    $path,
                    $statement['line'] + $this->lineDeltaForOffset($statement['code'], $nestedSource['offset']),
                );

                if ($issue !== null) {
                    return $issue;
                }
            }

            $callbackSources = $this->extractEagerLoadCallbackSources(
                $statement['code'],
                $rootSource['model'] ?? $tracked['model'] ?? null,
            );

            foreach ($callbackSources as $callbackSource) {
                if ($callbackSource['table'] === null) {
                    continue;
                }

                $issue = $this->detectMissingSchema(
                    $callbackSource['table'],
                    $callbackSource['body'],
                    $path,
                    $statement['line'] + $callbackSource['line_delta'],
                );

                if ($issue !== null) {
                    return $issue;
                }
            }

            if ($rootSource !== null) {
                $sanitizedStatement = $this->stripNestedSourceExpressions($statement['code'], $nestedSources);
                $sanitizedStatement = $this->stripEagerLoadCallbackBodies($sanitizedStatement, $callbackSources);
                $issue = $this->detectMissingSchema($rootSource['table'], $sanitizedStatement, $path, $statement['line']);
                if ($issue !== null) {
                    return $issue;
                }

                if ($rootSource['assignVar'] !== null && ! $this->containsTerminalMethod($sanitizedStatement)) {
                    $builders[$rootSource['assignVar']] = [
                        'table' => $rootSource['table'],
                        'model' => $rootSource['model'],
                    ];
                }

                continue;
            }

            if ($tracked === null) {
                continue;
            }

            $sanitizedStatement = $this->stripNestedSourceExpressions($statement['code'], $nestedSources);
            $sanitizedStatement = $this->stripEagerLoadCallbackBodies($sanitizedStatement, $callbackSources);
            $issue = $this->detectMissingSchema($tracked['table'], $sanitizedStatement, $path, $statement['line']);
            if ($issue !== null) {
                return $issue;
            }

            if ($tracked['assignVar'] !== null) {
                $builders[$tracked['assignVar']] = [
                    'table' => $tracked['table'],
                    'model' => $tracked['model'],
                ];
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, line: int}>
     */
    private function statements(string $contents): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($contents);
        $quote = null;
        $escapeNext = false;
        $statementStartOffset = null;

        for ($index = 0; $index < $length; $index++) {
            $char = $contents[$index];
            $buffer .= $char;

            if ($statementStartOffset === null && ! ctype_space($char)) {
                $statementStartOffset = $index;
            }

            if ($quote !== null) {
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
                continue;
            }

            if ($char !== ';') {
                continue;
            }

            $statement = trim($buffer);
            if ($statement !== '' && $statementStartOffset !== null) {
                $statements[] = [
                    'code' => $statement,
                    'line' => substr_count(substr($contents, 0, $statementStartOffset), "\n") + 1,
                ];
            }

            $buffer = '';
            $statementStartOffset = null;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $tailOffset = $statementStartOffset ?? 0;
            $statements[] = [
                'code' => $tail,
                'line' => substr_count(substr($contents, 0, $tailOffset), "\n") + 1,
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
     * @return array{table: string, assignVar: string|null, offset: int, model: string|null}|null
     */
    private function detectRootInlineSource(string $statement, array $imports, ?string $namespace): ?array
    {
        $sources = $this->nestedInlineSources($statement, $imports, $namespace, null);
        if ($sources === []) {
            return null;
        }

        $rootSource = $sources[0];
        $prefix = substr($statement, 0, $rootSource['offset']);
        $assignVar = null;

        if (preg_match('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*$/', $prefix, $assignment)) {
            $assignVar = $assignment[1];
        }

        return [
            'table' => $rootSource['table'],
            'assignVar' => $assignVar,
            'offset' => $rootSource['offset'],
            'model' => $rootSource['model'],
        ];
    }

    /**
     * @param array<string, string> $imports
     * @return list<array{table: string, offset: int, model: string|null}>
     */
    private function nestedInlineSources(string $statement, array $imports, ?string $namespace, ?int $rootOffset): array
    {
        $sources = [];

        if (preg_match_all('/(?:DB::|db\(\)->)table\(\s*["\']([A-Za-z_][A-Za-z0-9_]*)["\']\s*\)/', $statement, $tableMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($tableMatches[0] as $index => $fullMatch) {
                $offset = $fullMatch[1];
                if ($rootOffset !== null && $offset <= $rootOffset) {
                    continue;
                }

                $sources[] = [
                    'table' => $tableMatches[1][$index][0],
                    'offset' => $offset,
                    'model' => null,
                ];
            }
        }

        if (preg_match_all('/(?<!->)(((?:\\\\)?[A-Z][A-Za-z0-9_\\\\]*)::(?:query|where|orWhere|whereIn|whereNotIn|orderBy|groupBy|having|select|addSelect|pluck|value|firstWhere|update|insert)\s*\()/', $statement, $classMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($classMatches[0] as $index => $fullMatch) {
                $offset = $fullMatch[1];
                if ($rootOffset !== null && $offset <= $rootOffset) {
                    continue;
                }

                $model = $this->resolveModelInfo($classMatches[2][$index][0], $imports, $namespace);
                if ($model === null) {
                    continue;
                }

                $sources[] = [
                    'table' => $model['table'],
                    'offset' => $offset,
                    'model' => $model['fqcn'],
                ];
            }
        }

        usort($sources, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        return $sources;
    }

    /**
     * @param list<array{table: string, offset: int}> $nestedSources
     */
    private function stripNestedSourceExpressions(string $statement, array $nestedSources): string
    {
        foreach (array_reverse($nestedSources) as $nestedSource) {
            $expression = $this->extractExpressionFromOffset($statement, $nestedSource['offset']);
            $statement = substr_replace($statement, 'null', $nestedSource['offset'], strlen($expression));
        }

        return $statement;
    }

    private function extractExpressionFromOffset(string $statement, int $offset): string
    {
        $length = strlen($statement);
        $stack = [];
        $quote = null;
        $escapeNext = false;
        $expression = '';

        for ($index = $offset; $index < $length; $index++) {
            $char = $statement[$index];

            if ($quote !== null) {
                $expression .= $char;

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
                $expression .= $char;
                continue;
            }

            if (in_array($char, ['(', '[', '{'], true)) {
                $stack[] = $char;
                $expression .= $char;
                continue;
            }

            if (in_array($char, [')', ']', '}'], true)) {
                if ($stack === []) {
                    break;
                }

                array_pop($stack);
                $expression .= $char;
                continue;
            }

            if (($char === ',' || $char === ';') && $stack === []) {
                break;
            }

            $expression .= $char;
        }

        return trim($expression);
    }

    private function lineDeltaForOffset(string $statement, int $offset): int
    {
        return substr_count(substr($statement, 0, $offset), "\n");
    }

    /**
     * @param array<string, string> $delimiters
     * @return array{code: string, length: int}
     */
    private function extractDelimitedSegment(string $statement, int $offset, array $delimiters): array
    {
        $length = strlen($statement);
        $stack = [];
        $quote = null;
        $escapeNext = false;
        $segment = '';
        $consumed = 0;

        for ($index = $offset; $index < $length; $index++) {
            $char = $statement[$index];

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

    /**
     * @return list<array{table: string|null, offset: int, length: int, body: string, line_delta: int}>
     */
    private function extractEagerLoadCallbackSources(string $statement, ?string $rootModel): array
    {
        if ($rootModel === null) {
            return [];
        }

        $relations = $this->modelMetadata()['fqcn'][$rootModel]['relations'] ?? [];
        $sources = [];

        if (! preg_match_all('/["\']([^"\']+)["\']\s*=>\s*(fn\s*\([^)]*\)\s*=>|function\s*\([^)]*\)\s*\{)/', $statement, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as $index => $fullMatch) {
            $relationPath = $matches[1][$index][0];
            $relation = explode('.', $relationPath, 2)[0];
            $callbackStart = $matches[2][$index][1];
            $callbackHead = $matches[2][$index][0];
            $table = $relations[$relation] ?? null;

            if (str_starts_with($callbackHead, 'fn')) {
                $arrowOffset = strpos($statement, '=>', $callbackStart);
                if ($arrowOffset === false) {
                    continue;
                }

                $bodyStart = $arrowOffset + 2;
                while (isset($statement[$bodyStart]) && ctype_space($statement[$bodyStart])) {
                    $bodyStart++;
                }

                $segment = $this->extractDelimitedSegment($statement, $bodyStart, [',', ']', ')']);
                $sources[] = [
                    'table' => $table,
                    'offset' => $callbackStart,
                    'length' => ($bodyStart - $callbackStart) + $segment['length'],
                    'body' => $segment['code'],
                    'line_delta' => $this->lineDeltaForOffset($statement, $bodyStart),
                ];

                continue;
            }

            $braceStart = strpos($statement, '{', $callbackStart);
            if ($braceStart === false) {
                continue;
            }

            $braceDepth = 1;
            $quote = null;
            $escapeNext = false;
            $end = null;
            $length = strlen($statement);

            for ($cursor = $braceStart + 1; $cursor < $length; $cursor++) {
                $char = $statement[$cursor];

                if ($quote !== null) {
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
                    continue;
                }

                if ($char === '{') {
                    $braceDepth++;
                    continue;
                }

                if ($char !== '}') {
                    continue;
                }

                $braceDepth--;
                if ($braceDepth === 0) {
                    $end = $cursor;
                    break;
                }
            }

            if ($end === null) {
                continue;
            }

            $bodyStart = $braceStart + 1;
            $sources[] = [
                'table' => $table,
                'offset' => $callbackStart,
                'length' => ($end + 1) - $callbackStart,
                'body' => trim(substr($statement, $bodyStart, $end - $bodyStart)),
                'line_delta' => $this->lineDeltaForOffset($statement, $bodyStart),
            ];
        }

        return $sources;
    }

    /**
     * @param list<array{table: string|null, offset: int, length: int, body: string, line_delta: int}> $callbackSources
     */
    private function stripEagerLoadCallbackBodies(string $statement, array $callbackSources): string
    {
        foreach (array_reverse($callbackSources) as $callbackSource) {
            $statement = substr_replace($statement, 'null', $callbackSource['offset'], $callbackSource['length']);
        }

        return $statement;
    }

    /**
     * @param array<string, array{table: string, model: string|null}> $builders
     * @return array{table: string, model: string|null, assignVar: string|null}|null
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
            'table' => $builders[$sourceVar]['table'],
            'model' => $builders[$sourceVar]['model'],
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
        return $this->resolveModelInfo($candidate, $imports, $namespace)['table'] ?? null;
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
     * @param array{fqcn: array<string, array{table: string, relations: array<string, string>}>, basename: array<string, list<string>>} $map
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
     * @return array{fqcn: array<string, array{table: string, relations: array<string, string>}>, basename: array<string, list<string>>}
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
        $modelFiles = [];

        if (! is_dir($appPath)) {
            $this->modelMetadata = $map;

            return $this->modelMetadata;
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
            $map['fqcn'][$fqcn] = [
                'table' => $table ?? Str::snake(Str::pluralStudly($class)),
                'relations' => [],
            ];
            $map['basename'][$class] ??= [];
            $map['basename'][$class][] = $fqcn;
            $modelFiles[$fqcn] = [
                'contents' => $contents,
                'imports' => $this->parseImports($contents),
                'namespace' => $namespace,
            ];
        }

        foreach ($modelFiles as $fqcn => $modelFile) {
            if (! preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{.*?return\s+\$this->(?:hasOne|hasMany|belongsTo|belongsToMany|hasOneThrough|hasManyThrough|morphOne|morphMany|morphToMany)\(\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class/si', $modelFile['contents'], $relationMatches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($relationMatches as $relationMatch) {
                $relationModel = $this->resolveModelInfoFromMap($map, $relationMatch[2], $modelFile['imports'], $modelFile['namespace']);
                if ($relationModel === null) {
                    continue;
                }

                $map['fqcn'][$fqcn]['relations'][$relationMatch[1]] = $relationModel['table'];
            }
        }

        $this->modelMetadata = $map;

        return $this->modelMetadata;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)))
            : str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
