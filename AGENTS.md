# AGENTS.md

## Package purpose

This Laravel package supplies the foundation for safely detecting, normalizing, fingerprinting, and aggregating HTTP 500 errors from Laravel and future web-server log drivers. Keep it application-agnostic and reusable.

## Compatibility

- PHP 8.2+
- Laravel 10, 11, and 12

## Directory responsibilities

- `src/Contracts`: stable, framework-light extension seams.
- `src/DTO`: immutable transfer objects.
- `src/Services`: masking, normalization, fingerprinting, and orchestration.
- `src/Repositories`: persistence implementations only.
- `src/Models`: package-owned Eloquent models only.
- `src/Commands`: concise Artisan entry points.
- `config`: published package configuration.
- `database/migrations`: package-owned schema.
- `tests`: synthetic unit and Laravel integration tests; `tests/Doubles` holds the fake log drivers used to exercise the pipeline.
- `.github/workflows`: the quality gates, run on every push and pull request.

## Coding conventions

Use strict types, PHP 8.2 `readonly` DTOs where possible, PSR-4 namespaces, small dependency-injected classes, and Laravel Pint formatting. Keep new behavior behind contracts when it is a future source/driver concern. Avoid unnecessary Facades, especially outside commands and framework boundaries.

## Validation

```bash
composer install
composer check          # format:test, analyse, test
composer test           # PHPUnit only
composer format         # Pint, writing
composer format:test    # Pint, check only
composer analyse        # PHPStan
```

CI (`.github/workflows/ci.yml`) runs the same gates across PHP 8.2/8.3/8.4 and
Laravel 10/11/12, then migrates and runs both Artisan commands inside a
Testbench application. Combinations the framework does not support are left out
of the matrix instead of being excluded afterwards.

Coverage is not a target in itself, but masking, normalization, fingerprinting,
duplicate protection, the Artisan commands (including their exit codes) and the
service provider registration must stay covered.

## Backward compatibility

Contracts, DTO constructor parameters, config keys, migration identifiers, Artisan command names, and persisted column semantics are public API. Do not change them incompatibly in a minor release. When changing public API, add or update tests and update `CHANGELOG.md`.

## Security

Mask before persistence, display, export, or fingerprinting. Do not retain original sensitive values. Never add production logs, real IP addresses, emails, cookies, session IDs, CSRF tokens, API keys, or access tokens to fixtures or Git. Use clearly synthetic examples such as `example.invalid` and documentation IP ranges only.

## Configuration

Defaults live in `config/error-monitor.php` and nowhere else. Read them through
`config('error-monitor....')` with the same default as the file, and add new keys
with a default so a published, out-of-date config file keeps working.

## Deferred work

Do not implement GitHub Issue/API integration or XServer-specific handling yet.
`Contracts\IssuePublisher` is a contract only - no implementation belongs in this
package, and the `github` configuration section must stay unread by any HTTP
call. Do not call any AI agent API (Codex, Claude, ...) from this package;
that orchestration belongs in GitHub Actions. Future Apache and XServer
collectors must implement the existing contracts without adding
application-model dependencies.

A parser must report how it established the HTTP status of an event
(`metadata.status_source`: `context`, `exception_class` or `assumed`, plus
`metadata.status_estimated`). Do not let an assumed `500` reach storage as a
stated one - the `status_codes` filter depends on that distinction.
