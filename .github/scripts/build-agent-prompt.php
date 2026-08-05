<?php

declare(strict_types=1);

use ErrorMonitor\Workflow\IssueAgentPromptBuilder;

require_once __DIR__.'/IssueAgentPromptBuilder.php';

[$script, $mode, $inputPath, $outputPath, $branch] = array_pad($argv, 5, '');

if (! in_array($mode, ['plan', 'implement'], true) || ! is_file($inputPath) || $outputPath === '') {
    fwrite(STDERR, "Usage: php {$script} <plan|implement> <issue-json> <output-file> [branch]\n");
    exit(1);
}

/** @var array<string, mixed> $issue */
$issue = json_decode((string) file_get_contents($inputPath), true, flags: JSON_THROW_ON_ERROR);
$builder = new IssueAgentPromptBuilder($issue);
$prompt = $mode === 'plan' ? $builder->plan() : $builder->implement($branch);

if (file_put_contents($outputPath, $prompt, LOCK_EX) === false) {
    fwrite(STDERR, "The prompt file could not be written.\n");
    exit(1);
}
