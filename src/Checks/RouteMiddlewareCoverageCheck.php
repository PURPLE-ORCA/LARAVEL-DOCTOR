<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use Illuminate\Routing\Route;
use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class RouteMiddlewareCoverageCheck implements DoctorCheck
{
    private const DOCS_URL = 'https://laravel.com/docs/routing#route-groups';

    /**
     * @var list<string>
     */
    private const ACCESS_CONTROL_MIDDLEWARE = [
        'auth',
        'verified',
        'can',
        'role',
        'permission',
        'abilities',
        'ability',
        'password.confirm',
    ];

    /**
     * @var list<string>
     */
    private const STRONG_SENSITIVE_KEYWORDS = [
        'admin',
        'dashboard',
        'account',
        'settings',
        'billing',
        'profile',
    ];

    /**
     * @var list<string>
     */
    private const WRITE_RISK_KEYWORDS = [
        'admin',
        'dashboard',
        'account',
        'settings',
        'billing',
        'profile',
        'course',
        'lesson',
        'curriculum',
        'content',
        'media',
        'upload',
        'manage',
    ];

    /**
     * @var list<string>
     */
    private const PUBLIC_ALLOWLIST_KEYWORDS = [
        'login',
        'register',
        'password',
        'forgot-password',
        'reset-password',
        'verification.notice',
        'verification.verify',
        'verify-email',
        'webhook',
        'callback',
        'health',
        'up',
        'status',
        'sanctum/csrf-cookie',
    ];

    public function name(): string
    {
        return 'Route Middleware Coverage';
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

        if ($routes === []) {
            return DoctorCheckResult::pass('No routes registered');
        }

        $duplicateName = $this->findDuplicateRouteName($routes);
        if ($duplicateName !== null) {
            return DoctorCheckResult::fail(
                sprintf(
                    'Duplicate route name detected: `%s` is registered for %s and %s',
                    $duplicateName['name'],
                    $this->describeRoute($duplicateName['first']),
                    $this->describeRoute($duplicateName['second']),
                ),
                'Rename one of the routes so helpers, policies, and redirects resolve a single canonical endpoint.',
                'Duplicate route names silently shadow intent and can send users or authorization checks to the wrong endpoint.',
                self::DOCS_URL,
            );
        }

        $familyDrift = $this->findAuthFamilyDrift($routes);
        if ($familyDrift !== null) {
            return DoctorCheckResult::fail(
                sprintf(
                    'Route family `%s` has middleware drift: %s is public while sibling routes are guarded',
                    $familyDrift['family'],
                    $this->describeRoute($familyDrift['route']),
                ),
                'Align this route with the rest of its family by moving it into the shared middleware group or adding the missing guard middleware.',
                'One unguarded sibling inside an otherwise protected route family often leaks access after refactors or copy-paste changes.',
                self::DOCS_URL,
            );
        }

        $missingAuth = $this->findMissingAuthRisk($routes);
        if ($missingAuth !== null) {
            return DoctorCheckResult::fail(
                sprintf(
                    'Sensitive route %s is missing auth middleware',
                    $this->describeRoute($missingAuth),
                ),
                'Wrap this route in an auth-protected group or add `->middleware(["auth"])` explicitly.',
                'Public sensitive routes can expose admin surfaces, account data, or unsafe mutations without authentication.',
                self::DOCS_URL,
            );
        }

        return DoctorCheckResult::pass('No obvious route / middleware coverage risks found');
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

        $search = strtolower(implode(' ', array_filter([
            $name,
            $uri,
            $action,
        ])));

        return [
            'name' => $name,
            'uri' => $uri,
            'methods' => $methods,
            'action' => $action,
            'middleware' => $middleware,
            'search' => $search,
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
            'authorize' => 'can',
            'passwordconfirm' => 'password.confirm',
            'rolemiddleware' => 'role',
            'permissionmiddleware' => 'permission',
            default => $middleware,
        };
    }

    /**
     * @param list<array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}> $routes
     * @return array{name: string, first: array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}, second: array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}}|null
     */
    private function findDuplicateRouteName(array $routes): ?array
    {
        $seen = [];

        foreach ($routes as $route) {
            if ($route['name'] === null || $route['name'] === '') {
                continue;
            }

            if (array_key_exists($route['name'], $seen)) {
                return [
                    'name' => $route['name'],
                    'first' => $seen[$route['name']],
                    'second' => $route,
                ];
            }

            $seen[$route['name']] = $route;
        }

        return null;
    }

    /**
     * @param list<array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}> $routes
     * @return array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}|null
     */
    private function findMissingAuthRisk(array $routes): ?array
    {
        foreach ($routes as $route) {
            if ($this->isPublicAllowlisted($route) || $this->hasAccessControl($route)) {
                continue;
            }

            if (! $this->looksSensitive($route)) {
                continue;
            }

            return $route;
        }

        return null;
    }

    /**
     * @param list<array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}> $routes
     * @return array{family: string, route: array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string}}|null
     */
    private function findAuthFamilyDrift(array $routes): ?array
    {
        $families = [];

        foreach ($routes as $route) {
            if ($this->isPublicAllowlisted($route)) {
                continue;
            }

            $family = $this->familyKey($route);
            if ($family === null) {
                continue;
            }

            $families[$family][] = $route;
        }

        foreach ($families as $family => $familyRoutes) {
            if (count($familyRoutes) < 2) {
                continue;
            }

            $guarded = array_values(array_filter($familyRoutes, fn (array $route): bool => $this->hasAccessControl($route)));
            $public = array_values(array_filter($familyRoutes, fn (array $route): bool => ! $this->hasAccessControl($route)));

            if (count($guarded) >= 2 && count($public) >= 1) {
                return [
                    'family' => $family,
                    'route' => $public[0],
                ];
            }
        }

        return null;
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
     * @param array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string} $route
     */
    private function looksSensitive(array $route): bool
    {
        foreach (self::STRONG_SENSITIVE_KEYWORDS as $keyword) {
            if (str_contains($route['search'], $keyword)) {
                return true;
            }
        }

        if (! $this->hasWriteMethod($route)) {
            return false;
        }

        foreach (self::WRITE_RISK_KEYWORDS as $keyword) {
            if (str_contains($route['search'], $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string} $route
     */
    private function hasWriteMethod(array $route): bool
    {
        foreach ($route['methods'] as $method) {
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{name: string|null, uri: string, methods: list<string>, action: string, middleware: list<string>, search: string} $route
     */
    private function familyKey(array $route): ?string
    {
        if ($route['name'] !== null && $route['name'] !== '') {
            $segments = explode('.', $route['name']);
            $family = strtolower($segments[0]);

            if (in_array($family, self::STRONG_SENSITIVE_KEYWORDS, true)) {
                return $family;
            }
        }

        $uriPrefix = strtolower(explode('/', trim($route['uri'], '/'))[0] ?? '');

        if (in_array($uriPrefix, self::STRONG_SENSITIVE_KEYWORDS, true)) {
            return $uriPrefix;
        }

        return null;
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

        return sprintf('`%s` [%s %s]', $name, $methods, '/' . ltrim($route['uri'], '/'));
    }
}
