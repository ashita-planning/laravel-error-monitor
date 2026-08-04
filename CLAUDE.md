# CLAUDE.md

Instructions for Claude when working in this repository. `AGENTS.md` is the
source of truth for shared agent rules; keep both files in sync when either
changes.

## What this repository is

`ashita-planning/laravel-error-monitor` (`Apkk\LaravelErrorMonitor`) - a Laravel
package that detects, masks, normalizes, fingerprints and daily-aggregates HTTP
500 errors from Laravel and future web-server log drivers. It stays
application-agnostic and performs no outbound HTTP call.

- PHP 8.2+, Laravel 10 / 11 / 12
- Package auto-discovery, PSR-4, strict types everywhere

## Where to look first

| Question | File |
| --- | --- |
| How the pipeline fits together | `src/Services/ErrorMonitorAnalyzer.php` |
| How one daily run is orchestrated | `src/Services/DailyErrorMonitorRunner.php` |
| Public API | `src/Contracts/`, `src/DTO/` |
| How a log file becomes an event | `src/Parsers/LaravelLogParser.php`, `src/Parsers/ApacheAccessLogParser.php`, `src/Parsers/ApacheErrorLogParser.php` |
| How an Apache 5xx is tied to an exception | `src/Services/ApacheLaravelCorrelationService.php` |
| How a server failure is categorised | `src/Support/ServerErrorClassifier.php` |
| What makes two failures "the same" | `src/Services/Sha256FingerprintGenerator.php` |
| What gets masked | `src/Services/DefaultSensitiveDataMasker.php` |
| What gets normalized | `src/Services/DefaultLogNormalizer.php` |
| Duplicate protection | `src/Repositories/DatabaseErrorEventRepository.php` (per-payload history in `error_monitor_event_occurrences`) |

The order of the pipeline is fixed:

```
LogCollector -> LogParser -> SensitiveDataMasker -> LogNormalizer
             -> FingerprintGenerator -> daily aggregate -> ErrorEventRepository
```

Masking always runs before normalization, fingerprinting and persistence. Do not
reorder it.

## Rules

- `declare(strict_types=1);` in every file; typed parameters and returns; array
  shapes in PHPDoc.
- Keep configuration defaults in `config/error-monitor.php` only - never
  duplicate them in code.
- Avoid new Facade usage outside commands and framework boundaries; inject
  instead.
- Do not add dependencies, GitHub/AI API calls or XServer specific handling.
- Fixtures use synthetic values only: `example.invalid`, documentation IP ranges
  and obviously fake credentials. Never commit real logs or personal data.
- Do not create documentation files unless asked.

## After changing anything

```bash
composer test         # PHPUnit through Orchestra Testbench (SQLite in memory)
composer format       # Pint (format:test only checks)
composer analyse      # PHPStan
composer check        # all three
```

When public API changes - contracts, DTO constructor parameters, config keys,
migration identifiers, command names, persisted column semantics - update the
tests and `CHANGELOG.md` in the same change.

## Current scope

Implemented: configuration, contracts and DTOs, masking, normalization,
fingerprinting, daily aggregation with duplicate protection, the issue link
repository, the Laravel log driver (`Collectors/LaravelLogCollector` +
`Parsers/LaravelLogParser`), the Apache access log driver
(`Collectors/ApacheAccessLogCollector` + `Parsers/ApacheAccessLogParser` +
`Services/ApacheLaravelCorrelationService`), the Apache error log driver
(`Collectors/ApacheErrorLogCollector` + `Parsers/ApacheErrorLogParser` +
`Support/ServerErrorClassifier`) - all registered under the container tags by
default -
`error-monitor:analyze` (period, dry-run, force, JSON, run lock, exit codes 0-4),
`error-monitor:run` (the daily command: every source, correlation, publishing
hook, retention pruning, exit codes 0-5) and `error-monitor:status`.

Not implemented: GitHub Issue publishing, AI agent integration and XServer
collectors. Those belong in separate packages, not in this one.

The HTTP status of a parsed event is never asserted blindly: parsers record
`metadata.status_source` (`context` / `exception_class` / `assumed` /
`access_log` / `error_category`) and
`metadata.status_estimated`. Keep that when touching a parser - the
`status_codes` filter must not turn a guess into a fact.
