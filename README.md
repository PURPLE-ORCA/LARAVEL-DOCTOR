# Laravel Doctor

> The health check tool for Laravel apps. Run `php artisan doctor` and get a score.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sahraoui/laravel-doctor?style=flat-square)](https://packagist.org/packages/sahraoui/laravel-doctor)
[![Tests](https://img.shields.io/github/actions/workflow/status/sahraoui/laravel-doctor/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sahraoui/laravel-doctor/actions?query=workflow%3Arun-tests+branch%3Amain)
[![MIT License](https://img.shields.io/packagist/l/sahraoui/laravel-doctor?style=flat-square)](LICENSE.md)

*Not affiliated with or endorsed by Laravel LLC.*

## What It Does

Laravel Doctor runs a set of health checks against your Laravel application and gives you a score from 0-100. Think of it as `expo doctor` or `react-doctor` but for Laravel.

```bash
php artisan doctor
```

```
  🔬 Laravel Doctor

  Environment
    ✓ PHP 8.3.4 (requires >= 8.1)
    ✓ APP_KEY is set (63 chars)

  Security
    ✓ APP_DEBUG is OFF
    ✓ No known security advisories

  Performance
    ⚠ Config is not cached
      → Run: php artisan config:cache for production

  Score: 90/100
  1 warnings │ 4 passed
```

## Installation

```bash
composer require sahraoui/laravel-doctor --dev
```

That's it. The command `php artisan doctor` is now available.

## Checks

| Check | Category | What It Does |
|-------|----------|-------------|
| PHP Version | environment | Verifies PHP meets composer.json requirement |
| APP_KEY | environment | Checks key exists and is valid length |
| Debug Mode | security | Ensures APP_DEBUG is off in production |
| Config Cache | performance | Checks if config cache is fresh |
| Security Advisories | security | Runs `composer audit` for known CVEs |

## Configuration

Optional. Publish the config:

```bash
php artisan vendor:publish --tag=doctor
```

Add custom checks:

```php
// config/doctor.php
return [
    'checks' => [
        App\Checks\MyCustomCheck::class,
    ],
];
```

## Writing Custom Checks

Implement `Sahraoui\Doctor\Contracts\DoctorCheck`:

```php
<?php

namespace App\Checks;

use Sahraoui\Doctor\Contracts\DoctorCheck;
use Sahraoui\Doctor\Contracts\DoctorCheckResult;

final class MyCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'My Custom Check';
    }

    public function category(): string
    {
        return 'custom';
    }

    public function run(): DoctorCheckResult
    {
        // Your check logic here

        return DoctorCheckResult::pass('Everything looks good');
        // or: DoctorCheckResult::warn('Something might be wrong', 'Fix suggestion');
        // or: DoctorCheckResult::fail('Something is broken', 'How to fix it');
    }
}
```

## CI Usage

```yaml
# GitHub Actions
- name: Laravel Doctor
  run: |
    composer require sahraoui/laravel-doctor --dev
    php artisan doctor --json
```

## Scoring

- Start at 100
- Errors: -3 points each
- Warnings: -1 point each
- 80+ = healthy, 60-79 = needs attention, <60 = critical

## License

MIT. See [LICENSE.md](LICENSE.md).
