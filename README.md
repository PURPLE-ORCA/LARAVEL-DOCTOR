# Laravel Doctor

> Ship clean. `php artisan doctor` first.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/purple-orca/laravel-doctor?style=flat-square)](https://packagist.org/packages/purple-orca/laravel-doctor)
[![Tests](https://img.shields.io/github/actions/workflow/status/PURPLE-ORCA/LARAVEL-DOCTOR/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/PURPLE-ORCA/LARAVEL-DOCTOR/actions?query=workflow%3Arun-tests+branch%3Amain)
[![MIT License](https://img.shields.io/packagist/l/purple-orca/laravel-doctor?style=flat-square)](LICENSE.md)

Deterministically scans your Laravel app for schema drift, N+1 queries, security holes, queue health issues, and more checks — before they hit production. Works with all Laravel versions (11, 12, 13).

*Not affiliated with or endorsed by Laravel LLC.*

## Quick start

```bash
composer require purple-orca/laravel-doctor --dev
php artisan doctor
```

Want the full picture?

```bash
php artisan doctor --all
```

## What it catches

| Check | What it catches |
|---|---|
| Schema Drift | Code that references columns your DB doesn't have |
| N+1 Queries | Relation-in-loop and Blade N+1 patterns |
| Route Middleware | Missing `auth`, duplicate route names |
| Authenticated Media | Public download endpoints, unsigned URLs |
| Queue Health | `sync` misconfig, stale jobs, Horizon mismatch |
| Unique Constraints | `unique` rules not backed by a DB index |
| Migration Status | Pending migrations before deploy |
| Scheduler Cron | Scheduled work with no cron wiring |
| *(+ 19 more)* | Environment, security, performance, infrastructure |

See [docs/CHECKS.md](docs/CHECKS.md) for the full catalog.

## Run in CI

```yaml
- name: Laravel Doctor
  run: php artisan doctor --json
```

Exit code `1` on any failing check — perfect for blocking PRs.

## Configuration

Laravel Doctor works out of the box. To add custom checks or customize:

```bash
php artisan vendor:publish --tag=doctor
```

## Custom checks

Implement `DoctorCheck` and register it in `config/doctor.php`:

```php
final class MyCheck implements DoctorCheck
{
    public function run(): DoctorCheckResult
    {
        // ...
    }
}
```

See [docs/CUSTOM_CHECKS.md](docs/CUSTOM_CHECKS.md) for examples.

## Commands

| Command | Description |
|---|---|
| `php artisan doctor` | Show warnings/errors + score |
| `php artisan doctor --all` | Show all 27 checks |
| `php artisan doctor --json` | Machine-readable JSON output |
| `php artisan doctor --category=security` | Run one category only |

## License

MIT. See [LICENSE.md](LICENSE.md).
