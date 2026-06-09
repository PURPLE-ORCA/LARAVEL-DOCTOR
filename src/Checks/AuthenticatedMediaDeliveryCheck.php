<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use Illuminate\Routing\Route;
use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class AuthenticatedMediaDeliveryCheck implements DoctorCheck
{
    private const DOCS_URL = 'https://laravel.com/docs/urls#signed-urls';

    /**
     * @var list<string>
     */
    private const ACCESS_CONTROL_MIDDLEWARE = [
        'auth',
        'signed',
        'verified',
        'password.confirm',
        'can',
        'role',
        'permission',
        'abilities',
        'ability',
    ];

    /**
     * @var list<string>
     */
    private const MEDIA_KEYWORDS = [
        'video',
        'videos',
        'media',
        'stream',
        'download',
        'attachment',
        'playlist',
        'embed',
        'file',
        'files',
    ];

    /**
     * @var list<string>
     */
    private const PUBLIC_ALLOWLIST_KEYWORDS = [
        'webhook',
        'callback',
        'health',
        'status',
        'up',
    ];

    public function __construct(
        private readonly ?string $rootPath = null,
    ) {}

    public function name(): string
    {
        return 'Authenticated Media Delivery';
    }

    public function category(): string
    {
        return 'security';
    }

    public function run(): DoctorCheckResult
    {
        $routes = array_map(
            fn (Route $route): array => $this->normalizeRoute($route),
            app('router')->getRoutes()->getRoutes(),
        );

        $publicMediaRoute = $this->findPublicMediaRoute($routes);
        if ($publicMediaRoute !== null) {
            return DoctorCheckResult::fail(
                sprintf(
                    'Media delivery route %s is missing meaningful access control',
                    $this->describeRoute($publicMediaRoute),
                ),
                'Protect this route with auth, signed URLs, or an equivalent access-control middleware before exposing media URLs or downloads.',
                'Public media delivery endpoints can leak private files, bypass entitlements, or create fragile player failures in production.',
                self::DOCS_URL,
            );
        }

        $refererIssue = $this->findRefererBasedMediaAuthorization();
        if ($refererIssue !== null) {
            return DoctorCheckResult::fail(
                sprintf(
                    'Referer-based media authorization detected in %s:%d — %s',
                    $refererIssue['file'],
                    $refererIssue['line'],
                    $refererIssue['snippet'],
                ),
                'Replace Referer/Referrer checks with real auth, policies, signed URLs, or token-based delivery.',
                'Referer headers are easy to strip or spoof and make private media delivery unreliable.',
                self::DOCS_URL,
            );
        }

        $unsignedIssue = $this->findUnsignedMediaUrlGenerator();
        if ($unsignedIssue !== null) {
            return DoctorCheckResult::fail(
                sprintf(
                    'Unsigned media URL generator detected in %s:%d — method `%s` returns a raw delivery URL without visible signing evidence',
                    $unsignedIssue['file'],
                    $unsignedIssue['line'],
                    $unsignedIssue['method'],
                ),
                'Use temporary URLs, signed routes, or provider-specific token/expiry parameters when generating private media links.',
                'Raw Bunny/media delivery URLs are easy to share, cache, or replay when they are not bound to an expiry or signature.',
                self::DOCS_URL,
            );
        }

        return DoctorCheckResult::pass('No obvious authenticated media delivery risks found');
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
     * @param list<array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}> $routes
     * @return array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}|null
     */
    private function findPublicMediaRoute(array $routes): ?array
    {
        foreach ($routes as $route) {
            if (! $this->looksLikeMediaRoute($route) || $this->isPublicAllowlisted($route)) {
                continue;
            }

            if ($this->hasAccessControl($route)) {
                continue;
            }

            return $route;
        }

        return null;
    }

    /**
     * @return array{file: string, line: int, snippet: string}|null
     */
    private function findRefererBasedMediaAuthorization(): ?array
    {
        foreach ($this->scanDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFilesIn($directory) as $path) {
                $contents = file_get_contents($path);
                if ($contents === false || ! $this->containsMediaKeywords($contents)) {
                    continue;
                }

                $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

                foreach ($lines as $index => $line) {
                    if (! preg_match('/referer|referrer|HTTP_REFERER/i', $line)) {
                        continue;
                    }

                    return [
                        'file' => $this->relativePath($path),
                        'line' => $index + 1,
                        'snippet' => trim($line),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array{file: string, line: int, method: string}|null
     */
    private function findUnsignedMediaUrlGenerator(): ?array
    {
        foreach ($this->scanDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFilesIn($directory) as $path) {
                $contents = file_get_contents($path);
                if ($contents === false || ! $this->containsPotentialDeliveryHost($contents)) {
                    continue;
                }

                foreach ($this->methodBlocks($contents) as $method) {
                    if (! $this->containsMediaKeywords($method['body'])) {
                        continue;
                    }

                    if (! $this->containsExposedMediaDeliveryUrl($method['body'])) {
                        continue;
                    }

                    if ($this->containsSigningEvidence($method['body'])) {
                        continue;
                    }

                    return [
                        'file' => $this->relativePath($path),
                        'line' => $method['line'],
                        'method' => $method['name'],
                    ];
                }
            }
        }

        return null;
    }

    private function containsMediaKeywords(string $contents): bool
    {
        $haystack = strtolower($contents);

        foreach (self::MEDIA_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function containsPotentialDeliveryHost(string $contents): bool
    {
        return preg_match('/mediadelivery\.net|playlist\.m3u8|video\.bunnycdn\.com|\/embed\//i', $contents) === 1;
    }

    private function containsExposedMediaDeliveryUrl(string $contents): bool
    {
        return preg_match('/mediadelivery\.net\/embed\/|playlist\.m3u8|\/embed\/[A-Za-z0-9_\-\{\$]/i', $contents) === 1;
    }

    private function containsSigningEvidence(string $contents): bool
    {
        return preg_match('/temporaryUrl\s*\(|temporarySignedRoute\s*\(|signedRoute\s*\(|URL::temporarySignedRoute\s*\(|URL::signedRoute\s*\(|generateSignedUrl\s*\(|getSecureEmbedUrl\s*\(|token\s*=.*expires|expires.*token|generateTusUploadSignature\s*\(/is', $contents) === 1;
    }

    /**
     * @return list<array{name: string, line: int, body: string}>
     */
    private function methodBlocks(string $contents): array
    {
        $methods = [];

        preg_match_all(
            '/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\([^)]*\)\s*(?::\s*[^{]+)?\s*\{/m',
            $contents,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $index => $fullMatch) {
            $start = (int) $fullMatch[1];
            $openBrace = $start + strlen((string) $fullMatch[0]) - 1;
            $end = $this->matchingBraceOffset($contents, $openBrace);

            if ($end === null) {
                continue;
            }

            $methods[] = [
                'name' => (string) $matches[1][$index][0],
                'line' => substr_count(substr($contents, 0, $start), "\n") + 1,
                'body' => (string) substr($contents, $start, (int) (($end - $start) + 1)),
            ];
        }

        return $methods;
    }

    private function matchingBraceOffset(string $contents, int $openBraceOffset): ?int
    {
        $depth = 0;
        $length = strlen($contents);

        for ($i = $openBraceOffset; $i < $length; $i++) {
            $char = $contents[$i];

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char !== '}') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string} $route
     */
    private function looksLikeMediaRoute(array $route): bool
    {
        return $this->containsMediaKeywords($route['search']);
    }

    /**
     * @param array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string} $route
     */
    private function hasAccessControl(array $route): bool
    {
        foreach ($route['middleware'] as $middleware) {
            if (in_array($middleware, self::ACCESS_CONTROL_MIDDLEWARE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string} $route
     */
    private function isPublicAllowlisted(array $route): bool
    {
        foreach (self::PUBLIC_ALLOWLIST_KEYWORDS as $keyword) {
            if (str_contains($route['search'], $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}
     */
    private function normalizeRoute(Route $route): array
    {
        $name = $route->getName();
        $uri = $route->uri();
        $methods = array_values(array_diff($route->methods(), ['HEAD']));
        $action = $route->getActionName();
        $middleware = array_values(array_unique(array_map(
            fn (string $middleware): string => $this->canonicalMiddleware($middleware),
            $route->gatherMiddleware(),
        )));

        return [
            'name' => $name,
            'uri' => $uri,
            'methods' => $methods,
            'action' => $action,
            'middleware' => $middleware,
            'search' => strtolower(implode(' ', array_filter([$name, $uri, $action]))),
        ];
    }

    private function canonicalMiddleware(string $middleware): string
    {
        $middleware = strtolower($middleware);
        $middleware = explode(':', $middleware)[0];
        $middleware = class_basename($middleware);

        return match ($middleware) {
            'authenticate' => 'auth',
            'ensureemailisverified' => 'verified',
            'validateSignature', 'validatesignature' => 'signed',
            'authorize' => 'can',
            'passwordconfirm' => 'password.confirm',
            'rolemiddleware' => 'role',
            'permissionmiddleware' => 'permission',
            default => $middleware,
        };
    }

    /**
     * @param array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string} $route
     */
    private function describeRoute(array $route): string
    {
        $methods = implode('|', $route['methods']);
        $name = $route['name'] !== null && $route['name'] !== ''
            ? $route['name']
            : '(unnamed route)';

        return sprintf('`%s` [%s /%s]', $name, $methods, ltrim($route['uri'], '/'));
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath ?? base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $root)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
