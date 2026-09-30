# AjoPlus API — Agent Guide

## Stack and commands

This is a Laravel 12 JSON API running on PHP 8.2+ with Laravel Sanctum authentication. The configured production-style datastore is MySQL; feature tests use in-memory SQLite.

```sh
composer install
php artisan migrate
php artisan serve
php artisan test
vendor/bin/pint --test
```

Use `composer run dev` when the local API, queue worker, log stream, and Vite process are all needed.

## Architecture

- `app/Http/Controllers/Api/`: Versioned API endpoints.
- `app/Http/Requests/`: Request validation.
- `app/Http/Middleware/`: API token protections.
- `app/Models/`: Eloquent records for users, groups, memberships, contributions, payouts, notifications, and payment attempts.
- `app/Support/`: Scheduling, payment reconciliation, and Paystack integration helpers.
- `database/migrations/`: Schema history; do not edit migrations already relied upon by environments.
- `routes/api.php`: All `/api/v1` routes.
- `tests/Feature/`: API workflow coverage.

## Financial and security requirements

- Persist and return money as integer kobo. Never introduce floating point values for monetary data.
- Treat client requests as untrusted. Enforce Sanctum authentication, ownership, and group membership server-side.
- Perform contribution, payout, and transaction state changes in database transactions.
- Design payment or webhook processing to be idempotent and concurrency-safe.
- Verify Paystack webhook signatures and payment references before changing payment state.
- Never log or commit passwords, access tokens, Paystack secret keys, bank details, raw webhook payloads, `.env`, or database dumps.

## Quality rules

- Add or update feature tests for each API behavior change.
- Run `php artisan test` and `vendor/bin/pint --test` before handing off changes.
- Use Laravel Form Requests for validation rather than controller-inline validation where an existing pattern applies.
- Keep API responses consistent with the current `/api/v1` contract; version breaking changes.
- Update `README.md` for changed setup, endpoints, environment variables, or payment behavior.

## Git hygiene

Track source, migrations, tests, `composer.lock`, and `.env.example`. Do not track `vendor/`, `node_modules/`, runtime logs, local SQLite files, generated frontend assets, IDE state, or secrets.
