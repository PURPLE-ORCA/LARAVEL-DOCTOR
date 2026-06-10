# Check Reference

Laravel Doctor currently ships **27 checks**. This page is the dry reference: what runs, what category it belongs to, and what the check is trying to protect.

## Environment

| Check | Purpose |
|---|---|
| PHP Version | Verifies the running PHP version satisfies the app's Composer requirement |
| APP_KEY | Ensures the encryption key exists and looks valid |
| APP_ENV | Verifies `APP_ENV` is set |
| APP_URL | Verifies `APP_URL` is set |
| .env file exists | Checks the app has a readable environment file when expected |
| SESSION_DRIVER | Verifies `SESSION_DRIVER` is configured |
| CACHE_DRIVER | Verifies `CACHE_DRIVER` is configured |
| QUEUE_CONNECTION | Verifies `QUEUE_CONNECTION` is configured |
| MAIL_MAILER | Verifies `MAIL_MAILER` is configured and environment-appropriate |
| Maintenance Mode | Reports whether the app is currently in maintenance mode |

## Security

| Check | Purpose |
|---|---|
| Debug Mode | Fails when `APP_DEBUG=true` in production-like environments |
| Authenticated Media Delivery | Flags exposed media/download routes, referer-only auth, and unsigned playback URL generation |
| Route Middleware Coverage | Flags duplicate route names, sensitive routes missing `auth`, and middleware drift inside guarded route families |
| Security Advisories | Surfaces known dependency advisories via Composer audit data |

## Performance

| Check | Purpose |
|---|---|
| Config Cache | Verifies config caching is enabled where it should be |
| N+1 Query Detection | Statically scans for strong N+1 patterns in PHP and Blade |
| Route Cache | Verifies route caching is enabled where appropriate |
| View Cache | Verifies view caching is enabled where appropriate |
| OPcache | Reports OPcache readiness in production-like environments |
| Storage Link | Verifies the public storage symlink exists |

## Infrastructure

| Check | Purpose |
|---|---|
| Database Connection | Verifies the app can talk to the configured database |
| Migration Status | Detects pending migrations |
| Queue Worker / Horizon Health | Flags queued work running on `sync`, missing queue tables, stale jobs, failed jobs, and obvious Horizon/backend mismatches |
| Schema Drift | Detects code referencing database columns that do not exist |
| Scheduler Cron | Detects missing scheduler cron wiring |
| Storage Writable | Verifies key storage paths are writable |
| Unique Constraint Coverage | Detects validator-only `unique` rules that are not backed by a real database unique index |

## Status semantics

| Status | Meaning |
|---|---|
| `pass` | Healthy or expected for the current environment |
| `warn` | Action recommended, but not definitive breakage |
| `fail` | Strong signal of a real problem |

## Design rules

Laravel Doctor check design follows a few rules:

1. **Incident-prevention first.** Checks should map to bugs and outages people actually hit.
2. **Environment-aware.** Local dev should not be punished for not looking like production.
3. **Conservative heuristics.** Better to miss a weak signal than spam teams with false positives.
4. **Actionable output.** Findings should explain both the problem and the next move.

## Categories on the CLI

You can run a single category:

```bash
php artisan doctor --category=security
php artisan doctor --category=performance
php artisan doctor --category=infrastructure
php artisan doctor --category=environment
```
