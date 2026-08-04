# GitHub issues

```dotenv
ERROR_MONITOR_GITHUB_ENABLED=true
ERROR_MONITOR_GITHUB_REPOSITORY=acme/shop
ERROR_MONITOR_GITHUB_TOKEN=github_pat_...
```

## Token permissions

A fine-grained personal access token needs **one** repository permission:

| Permission | Access |
| --- | --- |
| Issues | Read and write |

Nothing else — no code access, no workflow permission, no `repo` scope. Grant it
to the single repository issues are filed in.

If you must use a classic token, `public_repo` suffices for a public repository;
for a private one the narrowest classic scope is `repo`, which is far broader
than this package needs. That is a reason to prefer a fine-grained token.

## What happens

| Situation | Action |
| --- | --- |
| Failure not tracked anywhere | Open an issue |
| Already reported today, unchanged | Nothing at all |
| Open issue, new day or a worse day | Add a comment |
| Closed issue, failure returned | Reopen, label `regression`, comment |
| Could not be settled safely | Nothing recorded; the next run retries |

One issue per fingerprint per environment per repository — so five distinct
failures in one day produce five issues, and re-running that day produces none.

## How duplicates are prevented

Two records, because either alone has a blind spot:

- **The database link** is checked first. It can be behind — a previous run may
  have created an issue and lost the response before recording it.
- **HTML markers in the issue body and in each comment** are the fallback and
  the final authority. They are invisible, survive edits, and are matched
  exactly.

```
database link → GitHub search → GitHub issue list → create
```

The search index is fast but eventually consistent. The issue list reads the
repository directly. **After a lost write the search index is not consulted at
all**, because "not indexed yet" and "never created" are indistinguishable
through it — which is exactly what would produce a duplicate.

A write whose response never arrived is never simply resent: the adapter looks
for the side effect first and only retries once it is known not to have
happened.

## Concurrency

Looking and creating happen inside a distributed cache lock, because GitHub's
creation endpoint has no idempotency key and the gap between the check and the
write cannot be closed by searching harder.

**Use a shared cache store** (Redis, Memcached, or the database driver) if more
than one machine runs the schedule. Without a lock provider the package still
publishes, but the create-race protection is gone.

## The `regression` label

> Reopening the issue and posting the recurrence comment are **required** for
> success. The `regression` label is **supporting information**: if a transient
> API failure prevents it from being added, the recurrence comment is still
> posted. The outcome is recorded in the publication metadata
> (`regression_label_added`) and as a log warning.

Failing the whole publication over a label would leave the state worse: the next
run would find the issue already open with the day's comment posted, and would
never return to the labelling path.

## Two applications, one repository

The link is unique on `(provider, environment, fingerprint, target)`. Two
applications sharing a repository **and** an environment name will share issues
for any failure whose fingerprint matches. Give them different `environment`
values or different repositories unless that is what you want.

## GitHub Enterprise Server

```dotenv
ERROR_MONITOR_GITHUB_API_URL=https://github.example.com/api/v3
```

Nothing else changes.
