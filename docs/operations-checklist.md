# Operations checklist

## Before the first scheduled run

- [ ] `php artisan migrate` has run; `error-monitor:status` shows both tables
- [ ] `error-monitor:status` shows the log path exists and files are found
- [ ] `ERROR_MONITOR_TIMEZONE` matches the server's actual timezone
      (a mismatch silently stops correlation working)
- [ ] `error-monitor:run --dry-run --json` reports a plausible number per source
- [ ] `status_codes` includes the gateway errors you care about
      (`500,502,503,504` — the default is `500` alone)
- [ ] `retention_days` is what you want; it deletes
- [ ] The cache store can lock across every machine that runs the schedule

## XServer, if used

- [ ] Log storage is enabled in the control panel
- [ ] `error-monitor:xserver-status --date=yesterday` lists the expected files
- [ ] The schedule runs **after 07:00**, since logs appear around 06:00
- [ ] You know that a file dated the 4th mostly describes the 3rd

## GitHub, if used

- [ ] The token has **Issues: read and write** and nothing else
- [ ] `error-monitor:github-status --check-connection` succeeds
- [ ] The repository is not shared with another application using the same
      `environment` name
- [ ] A first real run was done with `--skip-github` if backfilling history

## The issue agent, if used

- [ ] `OPENAI_API_KEY` is set as a repository Actions secret
- [ ] The six issue-agent labels in
      [codex-issue-workflow.md](codex-issue-workflow.md) exist
- [ ] The smoke test in [codex-issue-workflow.md](codex-issue-workflow.md) has
      been done once — in particular that a planning run changes no files

## Monthly

- [ ] Exit codes from the scheduled runs are `0` (`5` means a source is failing)
- [ ] `error-monitor:status` still finds log files — rotation and path changes
      are the usual cause of silent gaps
- [ ] Open issues labelled `plan-review-required` have been looked at
- [ ] The dependency audit job is still green

## After an incident

- [ ] `error-monitor:run --date=<the day>` was re-run if the schedule was down
      (re-running is safe; nothing is double counted)
- [ ] The issue's `metadata` was read before acting — particularly
      `status_estimated` and `correlation_confidence`
