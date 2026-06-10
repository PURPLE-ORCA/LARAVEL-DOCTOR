# Laravel Doctor

> High-signal health checks for Laravel apps. Run `php artisan doctor` and catch deploy blockers, security drift, and production footguns before they bite.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/purple-orca/laravel-doctor?style=flat-square)](https://packagist.org/packages/purple-orca/laravel-doctor)
[![Tests](https://img.shields.io/github/actions/workflow/status/PURPLE-ORCA/LARAVEL-DOCTOR/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/PURPLE-ORCA/LARAVEL-DOCTOR/actions?query=workflow%3Arun-tests+branch%3Amain)
[![MIT License](https://img.shields.io/packagist/l/purple-orca/laravel-doctor?style=flat-square)](LICENSE.md)

*Not affiliated with or endorsed by Laravel LLC.*

## Why Laravel Doctor exists

Most Laravel checklists are either too shallow or too noisy.

**Laravel Doctor** focuses on checks that prevent real incidents:
- schema drift between code and database
- validator/database uniqueness drift
- duplicate route names and auth drift
- exposed media delivery endpoints
- queued work silently stuck or disabled
- N+1 query patterns that keep surviving review
- pending migrations before deploy

It is closer to **react-doctor for Laravel** than a generic style linter: deterministic, actionable, and built to help humans and coding agents converge on a healthier app quickly.

## Quick start

```bash
composer require purple-orca/laravel-doctor --dev
php artisan doctor
```

Want the full picture instead of issue-only output?

```bash
php artisan doctor --all
```

## Example output

Real output from a local validation app, abridged:

```text
🔬 Laravel Doctor

  ✓ Scanned 27 checks in 1.2s

  Security > 1 error
  Infrastructure > 1 warning

  Security
    ✗ Duplicate route name detected: `curriculum.edit` is registered for `curriculum.edit` [GET /formations/{formation}/curriculum/{curriculum_item}/edit] and `curriculum.edit` [GET /formations/{formation}/curriculum/edit]
      Duplicate route names silently shadow intent and can send users or authorization checks to the wrong endpoint.
      → Rename one of the routes so helpers, policies, and redirects resolve a single canonical endpoint.
      See: https://laravel.com/docs/routing#route-groups
    ✓ No obvious authenticated media delivery risks found

  Infrastructure
    ⚠ Laravel scheduler is not configured in cron
      Without the scheduler cron entry, scheduled tasks (backups, reports, queue workers) will never run
    ✓ All migrations are up to date
    ✓ No obvious queue worker or Horizon health risks found
    ✓ No obvious schema drift patterns found

  Score: 96/100 Excellent
  █████████████████████████████░

  1 errors │ 1 warnings │ 24 passed
```

## What it catches

### Flagship incident-preventers

| Check | Category | What it catches |
|---|---|---|
| Schema Drift | infrastructure | Code that references columns your database does not have yet |
| Unique Constraint Coverage | infrastructure | Validator-only `unique` rules that are not backed by a real database unique index |
| Route Middleware Coverage | security | Sensitive routes missing `auth`, middleware drift, duplicate route names |
| Authenticated Media Delivery | security | Public media/download endpoints, referer-only protection, unsigned playback URLs |
| Queue Worker / Horizon Health | infrastructure | `ShouldQueue` work running on `sync`, missing queue tables, stale jobs, Horizon/backend mismatch |
| N+1 Query Detection | performance | Obvious relation-in-loop and Blade N+1 patterns |
| Migration Status | infrastructure | Pending migrations before or after deploy |
| Scheduler Cron | infrastructure | Apps with scheduled work but no system cron wiring |

These are the checks the package should be known for publicly. They catch the bugs and deploy mistakes that actually turn into incidents.

### Baseline support checks

The rest of the package is useful operational hygiene: PHP/framework config sanity, cache state, storage wiring, security advisory surfacing, and other environment-aware checks that help complete the picture without being the main product story.

### Full check catalog

Laravel Doctor currently ships **27 checks** across four categories.

For launch framing, think of the package as **flagship incident-preventers + baseline support checks** — not as a bag of env trivia.

| Category | Checks |
|---|---|
| Environment | PHP Version, APP_KEY, APP_ENV, APP_URL, .env file exists, SESSION_DRIVER, CACHE_DRIVER, QUEUE_CONNECTION, MAIL_MAILER, Maintenance Mode |
| Security | Debug Mode, Authenticated Media Delivery, Route Middleware Coverage, Security Advisories |
| Performance | Config Cache, N+1 Query Detection, Route Cache, View Cache, OPcache, Storage Link |
| Infrastructure | Database Connection, Migration Status, Queue Worker / Horizon Health, Schema Drift, Scheduler Cron, Storage Writable, Unique Constraint Coverage |

See [docs/CHECKS.md](docs/CHECKS.md) for the full reference.

## Command reference

| Command | What it does |
|---|---|
| `php artisan doctor` | Show only warnings/errors plus a score summary |
| `php artisan doctor --all` | Show every check, including passes |
| `php artisan doctor --json` | Emit machine-readable JSON and exit non-zero when any check fails |
| `php artisan doctor --category=security` | Run one category only (`environment`, `security`, `performance`, `infrastructure`) |

## Installation requirements

| Requirement | Supported |
|---|---|
| PHP | `^8.1` |
| Laravel | `^10.0`, `^11.0`, `^12.0` |
| Console | `symfony/console ^6.0|^7.0` |

## CI usage

### Minimal GitHub Actions step

```yaml
- name: Laravel Doctor
  run: php artisan doctor --json
```

### Typical workflow

```yaml
name: quality

on:
  pull_request:
  push:
    branches: [main]

jobs:
  laravel-doctor:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Laravel Doctor
        run: php artisan doctor --json
```

If you want human-readable logs in CI, use `php artisan doctor --all` instead.

## Configuration

Laravel Doctor works out of the box. If you want to register app-specific checks, publish the config file:

```bash
php artisan vendor:publish --tag=doctor
```

That creates `config/doctor.php`:

```php
<?php

return [
    'checks' => [
        // App\Checks\MyCustomCheck::class,
    ],
];
```

## Writing custom checks

A custom check implements `PurpleOrca\Doctor\Contracts\DoctorCheck` and returns a `DoctorCheckResult`.

```php
<?php

namespace App\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class RedisPrefixCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Redis Prefix';
    }

    public function category(): string
    {
        return 'infrastructure';
    }

    public function run(): DoctorCheckResult
    {
        $prefix = config('database.redis.options.prefix');

        if (blank($prefix)) {
            return DoctorCheckResult::warn(
                'Redis prefix is not configured',
                'Set a unique Redis prefix per environment or app',
                'Shared Redis instances can bleed cache, queue, or session data across apps.'
            );
        }

        return DoctorCheckResult::pass("Redis prefix is set to '{$prefix}'");
    }
}
```

Register it in `config/doctor.php`:

```php
return [
    'checks' => [
        App\Checks\RedisPrefixCheck::class,
    ],
];
```

More examples: [docs/CUSTOM_CHECKS.md](docs/CUSTOM_CHECKS.md)

## Result model

Each check returns one of three statuses:

| Status | Meaning |
|---|---|
| `pass` | Healthy or expected for the current environment |
| `warn` | Attention needed, but not necessarily broken |
| `fail` | Strong evidence of a real problem |

Checks can also return:
- **advice** — what to do next
- **impact** — why this matters
- **docsUrl** — where to read the authoritative fix guidance

## Scoring

Laravel Doctor starts at **100** and applies conservative deductions:

| Result | Deduction |
|---|---|
| `fail` | `-3` |
| `warn` | `-1` |
| `pass` | `0` |

Score labels:

| Score | Label |
|---|---|
| `90+` | Excellent |
| `80–89` | Good |
| `60–79` | Needs work |
| `<60` | Critical |

## Exit codes

| Situation | Exit code |
|---|---|
| No failing checks | `0` |
| One or more failing checks | `1` |

This applies to both standard console mode and `--json` mode.

## Philosophy

Laravel Doctor is intentionally **not** trying to be all of these at once:
- not a generic code style linter
- not a profiler
- not a full static analyzer
- not a production monitoring platform

The goal is narrower:

> Catch high-signal Laravel risks early, present them clearly, and make the next fix obvious.

That means the package prefers:
- strong, actionable findings
- environment-aware checks
- conservative heuristics over noisy speculation
- output that works for both humans and agents

## Roadmap direction

Current direction is to keep improving **incident-preventing** checks first, especially around database drift, route safety, auth boundaries, async delivery, and deployment hygiene.

## License

MIT. See [LICENSE.md](LICENSE.md).
