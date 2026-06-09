<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use PurpleOrca\Doctor\Checks\RouteMiddlewareCoverageCheck;
use PurpleOrca\Doctor\Enums\Status;

function reset_route_coverage_routes(): void
{
    app('router')->setRoutes(new RouteCollection);
}

beforeEach(function () {
    reset_route_coverage_routes();
});

afterEach(function () {
    reset_route_coverage_routes();
});

it('passes for guarded admin routes and expected public endpoints', function () {
    $router = app('router');

    $router->middleware('auth')->get('/admin/dashboard', fn () => 'ok')->name('admin.dashboard');
    $router->get('/login', fn () => 'ok')->name('login');
    $router->post('/webhooks/stripe', fn () => 'ok')->name('webhooks.stripe');
    $router->get('/courses', fn () => 'ok')->name('courses.index');

    $check = new RouteMiddlewareCoverageCheck;
    $result = $check->run();

    expect($check->name())->toBe('Route Middleware Coverage');
    expect($check->category())->toBe('security');
    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toBe('No obvious route / middleware coverage risks found');
});

it('flags a public admin route missing auth middleware', function () {
    $router = app('router');

    $router->get('/admin/courses', fn () => 'ok')->name('admin.courses.index');

    $check = new RouteMiddlewareCoverageCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('admin.courses.index');
    expect($result->message)->toContain('missing auth middleware');
    expect($result->advice)->toContain('auth');
});

it('flags duplicate route names', function () {
    $router = app('router');

    $router->get('/landing', fn () => 'ok')->name('marketing.home');
    $router->get('/pricing', fn () => 'ok')->name('marketing.home');

    $check = new RouteMiddlewareCoverageCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('Duplicate route name detected');
    expect($result->message)->toContain('marketing.home');
});

it('flags middleware drift inside a guarded route family', function () {
    $router = app('router');

    $router->middleware('auth')->get('/admin/users', fn () => 'ok')->name('admin.users.index');
    $router->middleware('auth')->get('/admin/reports', fn () => 'ok')->name('admin.reports.index');
    $router->get('/admin/audit-log', fn () => 'ok')->name('admin.audit.index');

    $check = new RouteMiddlewareCoverageCheck;
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('middleware drift');
    expect($result->message)->toContain('admin');
    expect($result->message)->toContain('admin.audit.index');
});
