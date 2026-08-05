<?php

declare(strict_types=1);

/**
 * Turn a GitHub issue event into the decision the workflow acts on.
 *
 * Reads the event payload GitHub wrote to disk rather than taking anything from
 * the environment as an expression: interpolating issue text into a shell or a
 * YAML expression is how untrusted input becomes execution.
 *
 * Writes plain key=value pairs to $GITHUB_OUTPUT and nothing to stdout that
 * could be mistaken for instructions.
 */

require_once __DIR__.'/IssueAgentDecision.php';

use ErrorMonitor\Workflow\IssueAgentDecision;

$eventPath = getenv('ISSUE_SNAPSHOT_PATH') ?: getenv('GITHUB_EVENT_PATH');

if ($eventPath === false || ! is_file($eventPath)) {
    fwrite(STDERR, "No event payload was available.\n");
    exit(1);
}

/** @var array<string, mixed> $payload */
$payload = json_decode((string) file_get_contents($eventPath), true) ?: [];
/** @var array<string, mixed> $issue */
$issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : $payload;

/** @var array<int, array<string, mixed>|string> $rawLabels */
$rawLabels = is_array($issue['labels'] ?? null) ? $issue['labels'] : [];

$labels = array_map(
    static fn (array|string $label): string => is_array($label) ? (string) ($label['name'] ?? '') : $label,
    $rawLabels,
);

/** @var array<int, array<string, mixed>|string> $rawComments */
$rawComments = is_array($issue['comments'] ?? null) ? $issue['comments'] : [];

$comments = array_map(
    static fn (array|string $comment): string => is_array($comment) ? (string) ($comment['body'] ?? '') : $comment,
    $rawComments,
);

$decision = new IssueAgentDecision(
    title: (string) ($issue['title'] ?? ''),
    body: (string) ($issue['body'] ?? ''),
    labels: $labels,
    authorAssociation: (string) ($issue['author_association'] ?? $issue['authorAssociation'] ?? 'NONE'),
    comments: $comments,
);

$number = (int) ($issue['number'] ?? 0);

$outputs = [
    'mode' => $decision->mode(),
    'reason' => $decision->reason(),
    'issue_number' => (string) $number,
    'branch' => $decision->branch($number),
    'plan_marker' => $decision->planMarker($number),
    'high_risk' => implode(',', $decision->highRiskCategories()),
];

$outputPath = getenv('GITHUB_OUTPUT');

if ($outputPath === false) {
    // Running locally: print for inspection rather than failing.
    foreach ($outputs as $key => $value) {
        printf("%s=%s\n", $key, $value);
    }

    exit(0);
}

$handle = fopen($outputPath, 'ab');

if ($handle === false) {
    fwrite(STDERR, "The step output file could not be opened.\n");
    exit(1);
}

foreach ($outputs as $key => $value) {
    // A reason can contain anything the issue title did, so it is written as a
    // heredoc with a random delimiter rather than on one line.
    $delimiter = 'EOF_'.bin2hex(random_bytes(8));
    fwrite($handle, sprintf("%s<<%s\n%s\n%s\n", $key, $delimiter, $value, $delimiter));
}

fclose($handle);
