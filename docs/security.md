# Security

## What is removed, and what is kept

Masking runs before anything is normalized, fingerprinted or stored. The
original values are never returned, hashed or logged.

| Removed | Kept |
| --- | --- |
| IP addresses, e-mail addresses, phone numbers | HTTP statuses, SQLSTATE and driver error codes |
| Bearer tokens, JWTs, `Authorization`, CSRF tokens | Line numbers, version numbers |
| Cookies, session identifiers | Amounts, quantities, memory limits |
| Passwords, API keys, client secrets | Normalized routes (`/orders/{id}`) |
| Query strings | PHP error constants |

Removing too much is its own failure, and a silent one: if `Amount 15000` and
`line 10234` were masked, two unrelated failures would share a fingerprint and
be reported as one. The integration suite asserts both halves — that the secrets
are gone **and** that the identifying values survive.

## Limits worth knowing

- The masker is **pattern based**. A secret in a format it does not recognise
  passes through. Add an entry to `masking.patterns` for anything specific to
  your application.
- Values longer than `masking.max_length` (256 KB) are truncated before masking,
  which bounds the cost of a pathological log line.
- If a rule cannot run at all, the value is **redacted rather than passed
  through**. Masking fails closed.
- `issue.include_context` is off by default. The context is masked, but an issue
  is read by more people than a database is.

## Credentials

- The GitHub token is read from configuration and travels only in the
  `Authorization` header. It never appears in a URL, a request body, an issue,
  a comment, an exception, a log line or `error-monitor:github-status`.
- GitHub error bodies can echo the request back — including its `Authorization`
  header — so only the response's `message` field is ever read. The whole body
  never enters an exception.
- The XServer adapter holds no credentials at all; it reads files already on
  disk.
- `ANTHROPIC_API_KEY` is a repository secret used only by the workflow, never
  by package code.

## The issue agent

An issue body is untrusted input. See
[claude-code-workflow.md](claude-code-workflow.md) for the gates. The important
ones: the actor must have write access, the planning job cannot modify a file,
and certain subjects are never implemented automatically regardless of labels.

## Fixtures

No real log, domain, IP address or credential is committed to any of the three
repositories. Fixtures use `example.invalid`, the documentation ranges
`203.0.113.0/24` and `2001:db8::/32`, and obviously fake tokens. CI greps the
fixtures for anything that looks otherwise.

## Reporting a problem

Open an issue **without** the `ai-fix` label, and without including the affected
values. Security is one of the subjects the agent never implements
automatically.
