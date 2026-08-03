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
- `tests`: synthetic unit and Laravel integration tests.

## Coding conventions

Use strict types, PHP 8.2 `readonly` DTOs where possible, PSR-4 namespaces, small dependency-injected classes, and Laravel Pint formatting. Keep new behavior behind contracts when it is a future source/driver concern. Avoid unnecessary Facades, especially outside commands and framework boundaries.

## Validation

```bash
composer install
composer test
composer format -- --test
composer analyse
```

## Backward compatibility

Contracts, DTO constructor parameters, config keys, migration identifiers, Artisan command names, and persisted column semantics are public API. Do not change them incompatibly in a minor release. When changing public API, add or update tests and update `CHANGELOG.md`.

## Security

Mask before persistence, display, export, or fingerprinting. Do not retain original sensitive values. Never add production logs, real IP addresses, emails, cookies, session IDs, CSRF tokens, API keys, or access tokens to fixtures or Git. Use clearly synthetic examples such as `example.invalid` and documentation IP ranges only.

## Deferred work

Do not implement GitHub Issue/API integration or XServer-specific handling yet. Do not call any Codex API from this package. Future Apache and XServer collectors must implement the existing contracts without adding application-model dependencies.
