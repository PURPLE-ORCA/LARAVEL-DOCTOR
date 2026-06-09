# Custom Checks

This is the practical guide for extending Laravel Doctor inside your own app.

## Contract

A custom check must implement `PurpleOrca\Doctor\Contracts\DoctorCheck`.

```php
<?php

namespace App\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class ExampleCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'Example Check';
    }

    public function category(): string
    {
        return 'infrastructure';
    }

    public function run(): DoctorCheckResult
    {
        return DoctorCheckResult::pass('Everything looks good');
    }
}
```

## Result helpers

Use the built-in helpers:

```php
DoctorCheckResult::pass('Everything looks good');

DoctorCheckResult::warn(
    'Redis prefix is not configured',
    'Set a unique Redis prefix per environment',
    'Shared Redis instances can bleed cache and queue state across apps.'
);

DoctorCheckResult::fail(
    'Pending migrations found',
    'Run: php artisan migrate --force',
    'Deploying code ahead of schema changes can break requests and jobs.',
    'https://laravel.com/docs/migrations'
);
```

Helper signatures:

| Helper | Signature |
|---|---|
| `pass` | `pass(string $message)` |
| `warn` | `warn(string $message, ?string $advice = null, ?string $impact = null, ?string $docsUrl = null)` |
| `fail` | `fail(string $message, ?string $advice = null, ?string $impact = null, ?string $docsUrl = null)` |

## Registering a custom check

Publish the package config:

```bash
php artisan vendor:publish --tag=doctor
```

Then register your check in `config/doctor.php`:

```php
return [
    'checks' => [
        App\Checks\ExampleCheck::class,
    ],
];
```

Laravel Doctor appends custom checks after the built-in set.

## Category guidance

Use the existing categories unless you have a strong reason not to:

| Category | Use for |
|---|---|
| `environment` | Required app/env configuration |
| `security` | Auth, exposure, unsafe defaults, dependency risk |
| `performance` | Caching, query patterns, runtime efficiency |
| `infrastructure` | Database, queues, scheduler, filesystem, deploy hygiene |

## Good custom checks

Strong custom checks usually have these properties:
- rooted in a real incident your team already hit
- deterministic, not speculative
- low-noise
- obvious next action
- environment-aware when needed

Examples:
- Redis prefix missing in shared infra
- Horizon disabled in production cluster
- Required S3 bucket env missing for media workers
- Tenant cache isolation not configured

## Bad custom checks

Avoid checks that are mostly policy opinions with weak signal:
- naming style complaints
- generic "code quality" vibes
- broad architecture lectures without evidence
- checks that fail local dev for being local dev

## Testing custom checks

Treat custom checks like any other application logic:
- unit test the failure path
- unit test the pass path
- verify advice text is specific enough to act on
- prefer one strong finding over five vague ones
