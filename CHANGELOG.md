# Changelog

All notable changes to `ashita-planning/laravel-error-monitor` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- The integration application accepts Testbench 11 and PHPUnit 12, and
  `.github/workflows/integration.yml` runs the three packages together on
  Laravel 13 as well as 12 - the newest two framework releases are where the
  agreement between the packages is most likely to shift.

## [1.1.0] - 2026-08-05

### Added

- Laravel 13 support: `illuminate/console`, `illuminate/database` and
  `illuminate/support` accept `^13.0`, the development dependencies accept
  Testbench 11 and PHPUnit 12, and the CI matrix runs Laravel 13 on
  PHP 8.3/8.4/8.5 - once against the lowest resolvable 13.x releases, since
  `^13.0` is a claim about 13.0, not about the newest 13.x. Laravel 13 itself
  requires PHP 8.3; the package keeps `php: ^8.2` because Laravel 10-12 still
  run there, and choosing 13 makes Composer enforce 8.3 through the framework.
  No code change was needed.

## [1.0.0] - 2026-08-05

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
- CI job `security`: audits the newest resolvable dependency set under the
  normal Composer policy, so the compatibility matrix can install an
  advisory-carrying Laravel 10 without losing the security gate.
- Laravel log driver: `Collectors\LaravelLogCollector` discovers the `single`
  and `daily` channel layouts (the configured path may be the directory or any
  file inside it), newest first, bounded by file count and file size;
  `Parsers\LaravelLogParser` streams the Monolog default format, including
  multi-line stack traces, the JSON context and the `[stacktrace]` block. Both
  are bound and tagged by the service provider, so an analysis works out of the
  box. `Support\ApplicationFrameDetector` flags application frames and
  `Support\HttpStatusResolver` maps a throwable to the HTTP status the
  framework would answer.
- Every parsed event records how its HTTP status was established in
  `metadata.status_source` (`context`, `exception_class` or `assumed`) together
  with `metadata.status_estimated`, so an assumed 500 is never presented as a
  fact.
- Configuration: `laravel_log_levels`, `laravel_log_max_files`,
  `laravel_log_max_bytes` and `fingerprint.vendor_paths`.
- Table `error_monitor_event_occurrences` and model
  `ErrorMonitorEventOccurrence`: one row per distinct payload merged into a
  daily aggregate, unique on `(error_monitor_event_id, payload_hash)`, reachable
  through `ErrorMonitorEvent::occurrences()`.
- Apache access log driver: `Collectors\ApacheAccessLogCollector` finds the
  rotated and gzip generations alongside the live log;
  `Parsers\ApacheAccessLogParser` reads Common and Combined Log Format, streams
  `.gz` without expanding it onto disk, accepts extra named-group regexes for a
  custom `LogFormat`, and turns only the configured status range into events.
  The status is reported by the server, so events carry
  `metadata.status_source = access_log` and `status_estimated = false`.
- `Services\ApacheLaravelCorrelationService` matches an Apache 5xx to the Laravel
  exception that explains it - request id, then time plus method plus normalized
  path, then time plus path, then proximity alone - and records
  `metadata.correlation_method`, `correlation_confidence` and
  `correlation_candidates`. A 5xx with no counterpart is kept as its own event,
  which is the normal shape of a 502 or 503 that never reached PHP.
- `DTO\ErrorReportData` and `DTO\IssuePublicationResultData`: what the core
  hands an issue tracker and what it accepts back. The report is plain text with
  no Markdown, labels or links, because formatting is the adapter's judgement.
  Results carry a string identifier - GitHub hands out `1234`, Jira `OPS-42` -
  and one of `created`, `commented`, `reopened`, `skipped` or `failed`.
- `error-monitor:run` builds a report for every stored failure and offers it to
  an `IssuePublisher` when one is installed, then records what came back. The
  same report is never offered twice, so a repeated run is not a repeated API
  call; a failed publication records nothing, so the next run retries.
- Migration `generalize_error_monitor_issues_table`: `provider`, `external_id`,
  `external_state` and `metadata` on `error_monitor_issues`. Additive only -
  `repository`, `issue_number`, `issue_state` and `pull_request_number` keep
  their meaning and are still written when the identifier is numeric.
- `Contracts\ServerLogSource`, `DTO\CollectedLogFileData`,
  `Services\LogSourceRegistry` and `Collectors\ServerLogSourceCollector`: the
  boundary an adapter package uses to supply logs the core cannot reach. An
  adapter tags its source under
  `ErrorMonitorServiceProvider::SERVER_LOG_SOURCE_TAG` and hands over readable
  local paths; the core validates existence and readability, deduplicates by
  `source + target_date + file_hash`, and reads the file with the parsers it
  already has. A core-only installation is unaffected: the registry is simply
  empty. Two sources may not share an id, and a source that throws is reported
  as a warning rather than taking the run down.
- `Support\LogSource`: the source keys the package knows by name.
- `error-monitor:run`: the daily command. Resolves the period (defaulting to
  yesterday), reads Laravel, Apache access and Apache error logs in that order,
  correlates them, stores idempotently, hands failures to an `IssuePublisher`
  when one is installed, and prunes past `retention_days`. Options `--date`,
  `--from`, `--to`, `--source`, `--dry-run`, `--skip-github`, `--force`,
  `--json`, a cache lock per period and exit codes 0-5.
- `Services\DailyErrorMonitorRunner` plus `DTO\RunResultData` and
  `DTO\SourceRunData`: per-source results, so one failing source neither
  discards the others' work nor hides itself in a total. Exit code 5 reports a
  partial run.
- `ErrorEventRepository::prune()` and `ErrorMonitorAnalyzer::accepts()`. The
  first gives retention a home next to the occurrence rows that cascade with it;
  the second lets an orchestrator ask the analyzer what counts as storable
  instead of reimplementing the rule.
- Configuration: `masking.phone_keys`, array keys whose value is replaced with
  `{phone}` however it is written.
- Configuration: `apache_access_log_path`, `apache_access_log_patterns`,
  `apache_access_status_codes`, `apache_access_patterns` and a `correlation`
  section (`enabled`, `window_seconds`).
- Apache error log driver: `Collectors\ApacheErrorLogCollector` and
  `Parsers\ApacheErrorLogParser` read the failures that never reach PHP -
  exhausted memory, timeouts, FastCGI transport errors, permission and
  configuration problems - joining a multi-line PHP stack trace into one event
  and streaming `.gz` generations. `Support\ServerErrorClassifier` sorts each
  entry into one of nine kinds, recorded in `metadata.error_category` with
  `category_source` and `category_estimated`.
- An error log states no HTTP status, so it is derived from the category and
  reported as `status_source = error_category` with `status_estimated = true`.
  `missing_file` maps to 404 and `permission` to 403, which keeps scanner noise
  out of the stored server errors under the default `status_codes`.
- Configuration: `apache_error_log_path`, `apache_error_log_patterns` and
  `apache_error_log_levels`.
- `Collectors\GlobLogCollector`, the shared directory/pattern/limit behaviour
  every file based collector now extends.
- Tests covering the above, `CHANGELOG.md` and `CLAUDE.md`.

- `.github/workflows/codex-issue-agent.yml`: the official
  `openai/codex-action@v1` investigates an issue labelled `ai-fix` under the
  `:read-only` permission profile; a plan that a person has approved with
  `plan-approved` is implemented under `:workspace` on `ai/issue-{number}` and
  opened as a draft pull request once `composer check` passes. The workflow,
  rather than Codex, owns comments, commits, pushes and PR creation. Concurrency
  is per issue, and duplicate plan and implementation comments are prevented by
  HTML markers.
- `.github/scripts/IssueAgentDecision.php`: the decision the workflow acts on -
  trusted actor, plan present in the body or a comment, plan approved, subject
  safe to automate - unit tested because a YAML `if:` expression cannot be.
  `IssueAgentPromptBuilder` serializes the untrusted issue snapshot into a
  prompt file without shell or workflow-expression interpolation.
  Authentication, payments, loyalty, production data, deletion, migrations,
  security, undocumented external APIs, non-reproducible failures and major
  dependency upgrades stop at a plan whatever labels are applied.
- `.github/ISSUE_TEMPLATE/ai-task.yml`, and `/.github` added to the
  `export-ignore` rules so CI tooling stays out of the distributed package.

- `tests/IntegrationApp/`: an application installing all three packages at once,
  and an end-to-end suite that takes an XServer-named gzip log through parsing,
  correlation, aggregation and publishing to a faked GitHub issue. Covers first
  detection, same-day re-runs, next-day recurrence, reopening after a fix, a
  lost create response, missing and corrupt archives, a rate-limited and a
  broken GitHub, retention, and a leak scan over everything the run wrote.
- `.github/workflows/integration.yml`: one representative environment
  (PHP 8.3 / Laravel 12), the three repositories checked out as siblings, plus a
  grep over the fixtures for credential-shaped strings and real-looking domains.
- `docs/`: installation, XServer, GitHub issues, scheduling, the issue agent,
  security, troubleshooting and an operations checklist.

### Fixed

- Masking read any bare run of five or more digits as a phone number, so
  `Amount 15000 JPY`, `line 10234` and `/orders/12345` came out as `{phone}`.
  Because masking runs before normalization, those values were gone before the
  normalizer could keep them - contradicting the documented guarantee that
  amounts, quantities and line numbers survive - and because the masked message
  feeds the fingerprint, unrelated failures could collapse into one. A number is
  now only masked when it looks like a phone number: separators, a leading `+`,
  a `TEL:` style label, or ten to eleven digits behind a leading zero. Keys
  listed in the new `masking.phone_keys` still mask their value outright.
- `test_it_publishes_configuration` left the published config in the shared
  Testbench skeleton. `mergeConfigFrom` merges only the top level, so the stale
  copy overrode the whole `masking` section and any config key added afterwards
  never reached the tests that ran after it. The test now cleans up.
- Re-analysing a log double counted. A daily aggregate can hold one
  `payload_hash`, so it only ever recognised the entry processed last; on a day
  where one fingerprint produced several distinct entries, every earlier entry
  looked unprocessed on the next run and was added again.
  `hasPayloadHash()` and the merge path now read the occurrence history, and
  its unique constraint keeps two racing runs from counting the same payload
  twice.
- `DatabaseErrorEventRepository` merged an already aggregated event into an
  existing daily row using only its representative `occurredAt`, ignoring
  `firstOccurredAt` and `lastOccurredAt`. The row's range could therefore end up
  narrower than the occurrences it stood for. Creating and updating a row now
  read the range the same way and the stored range only ever widens.
- Laravel 10: the Eloquent models declared their casts through the `casts()`
  method, which only exists from Laravel 11 and is silently ignored on 10. Dates
  came back as strings and `context` / `metadata` as raw JSON, so the repository
  failed with "Call to a member function format() on string" and stored nothing.
  Both models now use the `$casts` property, which every supported version
  honours, and a test pins the expectation.

### Changed

- `IssuePublisher` is now `enabled()`, `provider()`, `target()` and
  `publish(ErrorReportData): IssuePublicationResultData`. The old
  `publish(ErrorEventData): ?int` and `comment()` assumed a numeric issue number
  and a GitHub-shaped workflow; nothing implemented the contract yet, so nothing
  in the wild breaks.
- `IssueLinkRepository` takes a `provider` and a string `target`/`externalId`
  throughout, and gained `recordPublication()`. Custom implementations must
  follow the new signatures.
- `LogCollector` gained `source(): string`, so a run can say which collector
  produced what. Files still carry the source a parser claims them by, so this
  is descriptive rather than a routing key. Custom collectors must implement it.
- `LogFileData` gained `targetDate`, `fileHash`, `compressed` and `metadata`,
  all optional and only filled in for externally supplied files.
- `LogParser` gained `supports(LogFileData): bool`. The analyzer now hands a
  file to the first parser claiming it instead of to the first registered one,
  which is what lets several log formats coexist. Custom parsers must implement
  the new method.
- `fingerprint.application_paths` now defaults to the path fragments
  `app/,routes/,modules/,packages/` instead of the absolute `base_path('app')`,
  so a trace logged under a different deployment root still matches. The key
  was previously unused; it now decides which stack frames identify a failure.
- CI installs the compatibility matrix with `COMPOSER_NO_BLOCKING=1`. From
  Composer 2.9 an advisory on a package removes every affected version from the
  resolver pool, which made Laravel 10 and 11 uninstallable and left the matrix
  unable to test what it exists to test. The bypass is scoped to that one step
  and never enters `composer.json`, so nothing changes for anyone installing
  this package.
- CI runs the Artisan commands against a file SQLite database instead of
  `:memory:`. `migrate`, `status` and `analyze` are separate processes, and an
  in-memory database does not outlive the first one.
- `composer.lock` is no longer committed and is now ignored. A library resolves
  against whatever its host application allows, and the CI matrix is what pins
  versions; `composer validate --strict` therefore no longer needs
  `--no-check-lock`.
- `.gitignore` no longer swallows `tests/Fixtures/*.log`, which the log parser
  tests need.
- `error_monitor_events.payload_hash` keeps its column and is still written, but
  its meaning is now "the payload processed last". Duplicate protection reads
  `error_monitor_event_occurrences` instead. Anything relying on that column to
  decide whether a payload was seen has to query the occurrences.

### Notes

- GitHub Issue/API integration, AI agent integration and XServer specific
  handling remain deliberately unimplemented. `error_monitor_issues`, the
  `IssuePublisher` contract and the `github` configuration are reserved for that
  work.
