# Changelog

All notable changes to `ashita-planning/laravel-error-monitor` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `.github/workflows/ci.yml`: composer validate, install, Pint, PHPStan, PHPUnit,
  migrations and both Artisan commands across PHP 8.2/8.3/8.4 and Laravel 10/11/12.
- Composer scripts `format:test` and `check`.
- Configuration: `environment`, `laravel_log_patterns`, an `analysis` section
  (`context_before_seconds`, `context_after_seconds`, `lock_seconds`),
  `masking.remove_query_strings`, `masking.remove_headers`, `masking.mask_keys`,
  `masking.patterns`, `masking.max_length`, the `{secret}` replacement token,
  `fingerprint.include_line_number`, `fingerprint.include_method`,
  `fingerprint.include_route` and `github.token`.
- DTO `AnalysisWindowData` and a `metadata` attribute, constructor validation,
  `with()`, `applicationFrames()`, `detectedDate()` and `toArray()` on
  `ErrorEventData`. `AnalysisResultData` reports skipped events, the analyzed
  window, dry runs and how many sources were configured.
- Contracts `IssuePublisher` (contract only, no implementation) and
  `IssueLinkRepository`, plus `SensitiveDataMasker::maskArray()` and
  `LogNormalizer::normalizeRoute()`.
- Masking: array masking including key based replacement, credentials that are
  neither long nor random (`password`, `client_secret`, `refresh_token`,
  provider key formats, JWTs), query string removal, a length bound and a
  fail-closed guarantee when a rule cannot run.
- Normalization: framework generated files (Blade views, cached data, file
  sessions), digests and long random values, and route normalization.
- Fingerprinting: configurable materials and a fallback to vendor frames when a
  trace contains no application frame.
- Migration adding `context` and `metadata` to `error_monitor_events` and
  `resolved_at` to `error_monitor_issues`; both are persisted by the repository.
- `DatabaseIssueLinkRepository`: fingerprint/issue correspondence, state and
  resolution tracking, pull request number and duplicate comment protection.
- `error-monitor:analyze`: `--date`, `--from`, `--to`, `--source`, `--dry-run`,
  `--force`, `--json`, a cache lock per analyzed period and exit codes 0-4.
- `error-monitor:status`: environment, log path existence and readability,
  matched log files, analyzed status codes, masking state, results path,
  retention, issue table state and GitHub integration state, plus `--json`. The
  GitHub token is never printed.
- Service provider: migration publish tag, `IssueLinkRepository` binding,
  container tags for collector and parser drivers, and no migration loading or
  driver resolution while the package is disabled.
- Tests covering the above (74 tests), `CHANGELOG.md` and `CLAUDE.md`.

### Notes

- GitHub Issue/API integration, AI agent integration and XServer specific
  handling remain deliberately unimplemented. `error_monitor_issues`, the
  `IssuePublisher` contract and the `github` configuration are reserved for that
  work.
