# The issue agent

`.github/workflows/claude-issue-agent.yml` turns an issue into a plan, and an
approved plan into a draft pull request.

```
issue labelled ai-fix
  → investigate, post a plan          (no file may change)
  → a person reads it, adds plan-approved
  → implement on ai/issue-{number}, run composer check, open a draft PR
```

| Label | Meaning |
| --- | --- |
| `ai-fix` | Ask the agent to look at this issue |
| `plan-approved` | A person read the plan and wants it implemented |
| `ai-running` | An implementation is in progress |
| `ai-done` / `ai-failed` | How the last run ended |
| `plan-review-required` | The subject is never implemented automatically |

## Setup

A repository administrator must provide both:

1. The [Claude GitHub App](https://github.com/apps/claude) installed on the
   repository.
2. An `ANTHROPIC_API_KEY` repository secret.

The workflow does nothing without them.

## First smoke test

Do this once, on something that does not matter, before trusting it:

1. Install the Claude GitHub App.
2. Add `ANTHROPIC_API_KEY`.
3. Open a low-risk issue — a typo in a docblock, a missing test case.
4. Add **only** `ai-fix`.
5. Confirm a plan comment appears, and that **no file changed** and no branch
   was created.
6. Add `plan-approved`.
7. Confirm a draft pull request appears on `ai/issue-{number}`.
8. Confirm `main` has no new commits.

Step 5 is the one that matters. If a "planning" run can change files, the
approval gate means nothing.

## What it will not do

An issue body is untrusted input — anyone can open one on a public repository —
so the gates are on the actor and the subject, never on the text.

- Only `OWNER`, `MEMBER` and `COLLABORATOR` issues are acted on.
- The planning job may not modify a file; the job fails if the tree is dirty.
- Implementation needs a plan **and** `plan-approved`. Neither alone is enough.
- Authentication, payments, loyalty points, production data, deletions,
  migrations, security, undocumented external APIs, non-reproducible failures
  and major dependency upgrades **stop at a plan whatever labels are applied**.
  `plan-approved` is a judgement about a plan, not a waiver on the subject.
- Work happens only on `ai/issue-{number}`; the branch is verified before the
  push, so `main` is never written to.
- No pull request without a passing `composer check`, and always a draft.
- Failures comment the stage and how to retry, never the log.

The decision logic is in `.github/scripts/IssueAgentDecision.php` and is unit
tested, because a YAML `if:` expression cannot be.

## Retrying a failed run

Remove `ai-failed`, then re-apply `plan-approved`. Or take it over by hand from
the `ai/issue-{number}` branch if one was pushed.
