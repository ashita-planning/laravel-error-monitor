# The issue agent

`.github/workflows/codex-issue-agent.yml` uses the official
[`openai/codex-action@v1`](https://github.com/openai/codex-action) to turn an
issue into a plan, and an approved plan into a draft pull request.

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

A repository administrator must provide:

1. An OpenAI API key with sufficient API billing and usage limits.
2. That value stored as the `OPENAI_API_KEY` repository Actions secret.

No Claude GitHub App or Anthropic key is used. The OpenAI API key is separate
from a ChatGPT subscription. The workflow does nothing without the secret.

## First smoke test

Do this once, on something that does not matter, before trusting it:

1. Add `OPENAI_API_KEY` as a repository Actions secret.
2. Create the six labels listed above if they do not exist.
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
- Issue text and comments are fetched as JSON and written to a prompt file;
  they are never interpolated into a shell command or workflow expression.
- Planning uses Codex's `:read-only` permission profile. The job also fails if
  the tree is dirty, and only posts the plan after that check passes.
- Implementation needs a plan **and** `plan-approved`. Neither alone is enough.
- Authentication, payments, loyalty points, production data, deletions,
  migrations, security, undocumented external APIs, non-reproducible failures
  and major dependency upgrades **stop at a plan whatever labels are applied**.
  `plan-approved` is a judgement about a plan, not a waiver on the subject.
- Work happens only on `ai/issue-{number}`; the branch is verified before the
  push, so `main` is never written to.
- Implementation uses `:workspace`, whose default network policy is off. Codex
  edits files only; the workflow performs the commit, push and PR operations.
- No pull request without a passing `composer check`, and always a draft.
- Failures comment the stage and how to retry, never the log.

The decision logic is in `.github/scripts/IssueAgentDecision.php` and is unit
tested, because a YAML `if:` expression cannot be.

## Retrying a failed run

Remove `ai-failed`, then re-apply `plan-approved`. Or take it over by hand from
the `ai/issue-{number}` branch if one was pushed.
