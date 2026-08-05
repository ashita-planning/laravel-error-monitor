<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Workflow;

use PHPUnit\Framework\TestCase;

final class CodexIssueAgentWorkflowTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 2).'/.github/workflows/codex-issue-agent.yml';
        $workflow = file_get_contents($path);

        $this->assertNotFalse($workflow);
        $this->workflow = $workflow;
    }

    public function test_it_uses_codex_with_the_narrow_permission_profiles(): void
    {
        $this->assertSame(2, substr_count($this->workflow, 'uses: openai/codex-action@v1'));
        $this->assertSame(2, substr_count($this->workflow, 'openai-api-key: ${{ secrets.OPENAI_API_KEY }}'));
        $this->assertStringContainsString('permission-profile: ":read-only"', $this->workflow);
        $this->assertStringContainsString('permission-profile: ":workspace"', $this->workflow);
        $this->assertStringNotContainsString('ANTHROPIC_API_KEY', $this->workflow);
        $this->assertStringNotContainsString('claude-code-action', $this->workflow);
    }

    public function test_issue_text_only_reaches_codex_through_a_prompt_file(): void
    {
        $this->assertStringContainsString('php .github/scripts/build-agent-prompt.php', $this->workflow);
        $this->assertSame(2, substr_count($this->workflow, 'prompt-file: ${{ runner.temp }}/codex-'));
        $this->assertStringNotContainsString('${{ github.event.issue.body }}', $this->workflow);
        $this->assertStringNotContainsString('${{ github.event.issue.title }}', $this->workflow);
    }

    public function test_the_issue_snapshot_uses_fields_supported_by_gh_cli(): void
    {
        $this->assertStringContainsString('--json number,title,body,labels,comments', $this->workflow);
        $this->assertStringNotContainsString('authorAssociation', $this->workflow);
    }

    public function test_a_plan_is_checked_before_it_is_posted(): void
    {
        $codex = strpos($this->workflow, '- name: Investigate and propose');
        $verify = strpos($this->workflow, '- name: Verify nothing was changed');
        $post = strpos($this->workflow, '- name: Post the verified plan');

        $this->assertIsInt($codex);
        $this->assertIsInt($verify);
        $this->assertIsInt($post);
        $this->assertLessThan($verify, $codex);
        $this->assertLessThan($post, $verify);
    }

    public function test_the_workflow_checks_then_commits_then_pushes_then_opens_a_draft(): void
    {
        $verify = strpos($this->workflow, '- name: Verify the branch before pushing');
        $check = strpos($this->workflow, '- name: Run the quality gate');
        $commit = strpos($this->workflow, '- name: Commit the verified change');
        $push = strpos($this->workflow, '- name: Push');
        $pullRequest = strpos($this->workflow, '- name: Open a draft pull request');

        $this->assertIsInt($verify);
        $this->assertIsInt($check);
        $this->assertIsInt($commit);
        $this->assertIsInt($push);
        $this->assertIsInt($pullRequest);
        $this->assertLessThan($check, $verify);
        $this->assertLessThan($commit, $check);
        $this->assertLessThan($push, $commit);
        $this->assertLessThan($pullRequest, $push);
        $this->assertStringContainsString('git status --porcelain -- .github AGENTS.md', $this->workflow);
        $this->assertStringContainsString('gh pr create \\', $this->workflow);
        $this->assertStringContainsString('--draft', $this->workflow);
    }
}
