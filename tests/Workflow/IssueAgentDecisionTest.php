<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Workflow;

use ErrorMonitor\Workflow\IssueAgentDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the issue agent is allowed to do, and to what.
 *
 * This logic lives outside the package because it is CI tooling, but it is
 * tested here because it is the part that fails expensively: an unapproved
 * implementation, or an automated change to something nobody should automate.
 * A YAML `if:` expression cannot be tested; this can.
 */
final class IssueAgentDecisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2).'/.github/scripts/IssueAgentDecision.php';
    }

    public function test_an_issue_from_a_stranger_gets_nothing(): void
    {
        // Anybody can open an issue on a public repository, so the gate is who
        // asked rather than what the text says.
        $decision = $this->decision(
            body: $this->planBody(),
            labels: [IssueAgentDecision::LABEL_TRIGGER, IssueAgentDecision::LABEL_APPROVED],
            association: 'NONE',
        );

        $this->assertSame(IssueAgentDecision::MODE_NONE, $decision->mode());
        $this->assertStringContainsString('without write access', $decision->reason());
    }

    #[DataProvider('trustedAssociations')]
    public function test_a_person_with_repository_access_is_trusted(string $association): void
    {
        $this->assertTrue($this->decision(association: $association)->actorIsTrusted());
    }

    /** @return array<string, array{0: string}> */
    public static function trustedAssociations(): array
    {
        return [
            'owner' => ['OWNER'],
            'member' => ['MEMBER'],
            'collaborator' => ['COLLABORATOR'],
        ];
    }

    #[DataProvider('untrustedAssociations')]
    public function test_everyone_else_is_not(string $association): void
    {
        $this->assertFalse($this->decision(association: $association)->actorIsTrusted());
    }

    /** @return array<string, array{0: string}> */
    public static function untrustedAssociations(): array
    {
        return [
            'first time contributor' => ['FIRST_TIME_CONTRIBUTOR'],
            'contributor' => ['CONTRIBUTOR'],
            'nobody' => ['NONE'],
            'mannequin' => ['MANNEQUIN'],
        ];
    }

    public function test_an_issue_without_the_trigger_label_is_left_alone(): void
    {
        $decision = $this->decision(body: $this->planBody(), labels: []);

        $this->assertSame(IssueAgentDecision::MODE_NONE, $decision->mode());
    }

    public function test_an_issue_without_a_plan_gets_a_plan(): void
    {
        $decision = $this->decision(
            body: "## 概要\n\nOrders sometimes fail to save.",
            labels: [IssueAgentDecision::LABEL_TRIGGER],
        );

        $this->assertSame(IssueAgentDecision::MODE_PLAN, $decision->mode());
        $this->assertFalse($decision->hasPlan());
    }

    public function test_a_plan_without_approval_is_not_implemented(): void
    {
        $decision = $this->decision(body: $this->planBody(), labels: [IssueAgentDecision::LABEL_TRIGGER]);

        $this->assertTrue($decision->hasPlan());
        $this->assertSame(IssueAgentDecision::MODE_PLAN, $decision->mode());
        $this->assertStringContainsString('not yet approved', $decision->reason());
    }

    public function test_an_approved_plan_is_implemented(): void
    {
        $decision = $this->decision(
            body: $this->planBody(),
            labels: [IssueAgentDecision::LABEL_TRIGGER, IssueAgentDecision::LABEL_APPROVED],
        );

        $this->assertSame(IssueAgentDecision::MODE_IMPLEMENT, $decision->mode());
    }

    public function test_a_heading_alone_is_not_a_plan(): void
    {
        // Otherwise "implement this" means whatever the agent decides it means.
        $decision = $this->decision(
            body: "## 実装プラン\n\nSomething should be done about this.\n\n## 完了条件\n\n- It works\n",
            labels: [IssueAgentDecision::LABEL_TRIGGER, IssueAgentDecision::LABEL_APPROVED],
        );

        $this->assertFalse($decision->hasPlan(), 'A plan needs steps, not prose.');
        $this->assertSame(IssueAgentDecision::MODE_PLAN, $decision->mode());
    }

    public function test_a_plan_with_no_completion_criteria_is_not_a_plan(): void
    {
        $decision = $this->decision(
            body: "## 実装プラン\n\n1. Change the thing\n2. Test it\n",
            labels: [IssueAgentDecision::LABEL_TRIGGER, IssueAgentDecision::LABEL_APPROVED],
        );

        $this->assertFalse($decision->hasPlan(), 'Nobody could tell whether it worked.');
    }

    public function test_steps_belonging_to_another_section_do_not_count(): void
    {
        // The steps are under "テスト項目", not under the plan heading.
        $body = "## 実装プラン\n\nWe should probably fix it.\n\n## テスト項目\n\n- a\n- b\n\n## 完了条件\n\n- done\n";

        $this->assertFalse($this->decision(body: $body)->hasPlan());
    }

    #[DataProvider('highRiskSubjects')]
    public function test_some_subjects_are_never_automated(string $text, string $category): void
    {
        $decision = $this->decision(
            body: $this->planBody()."\n\n".$text,
            labels: [IssueAgentDecision::LABEL_TRIGGER, IssueAgentDecision::LABEL_APPROVED],
        );

        $this->assertContains($category, $decision->highRiskCategories());
        // An approved plan does not override this: the label is a judgement
        // about a plan, not a waiver on the subject.
        $this->assertSame(IssueAgentDecision::MODE_REVIEW_REQUIRED, $decision->mode());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function highRiskSubjects(): array
    {
        return [
            'authentication in japanese' => ['認証トークンの検証を直す', 'authentication'],
            'authorization in english' => ['Fix the permission check on the admin route', 'authentication'],
            'payment in japanese' => ['決済処理の二重請求を修正する', 'payment'],
            'refund in english' => ['The refund endpoint charges twice', 'payment'],
            'loyalty points' => ['ポイント付与が二重になる', 'loyalty'],
            'production data' => ['本番データの不整合を修復する', 'production-data'],
            'data deletion' => ['古いレコードのデータ削除を実装する', 'data-deletion'],
            'truncate in english' => ['We should truncate the staging table nightly', 'data-deletion'],
            'migration' => ['マイグレーションで列を追加する', 'migration'],
            'security' => ['セキュリティ上の脆弱性を修正する', 'security'],
            'sql injection' => ['Possible SQL injection in the search filter', 'security'],
            'unknown external api' => ['外部API仕様不明のまま連携する', 'unknown-external-api'],
            'not reproducible' => ['再現できない障害を調査する', 'not-reproducible'],
            'major dependency' => ['PHPUnit のメジャー更新に追随する', 'major-dependency'],
        ];
    }

    public function test_an_ordinary_issue_is_not_high_risk(): void
    {
        $decision = $this->decision(
            title: 'Apache access log parser drops the referer',
            body: $this->planBody(),
            labels: [IssueAgentDecision::LABEL_TRIGGER, IssueAgentDecision::LABEL_APPROVED],
        );

        $this->assertSame([], $decision->highRiskCategories());
        $this->assertSame(IssueAgentDecision::MODE_IMPLEMENT, $decision->mode());
    }

    public function test_the_title_is_searched_as_well_as_the_body(): void
    {
        $decision = $this->decision(
            title: '決済ログの集約が壊れている',
            body: $this->planBody(),
            labels: [IssueAgentDecision::LABEL_TRIGGER, IssueAgentDecision::LABEL_APPROVED],
        );

        $this->assertSame(IssueAgentDecision::MODE_REVIEW_REQUIRED, $decision->mode());
    }

    public function test_an_implementation_may_only_use_its_own_branch(): void
    {
        $this->assertSame('ai/issue-42', $this->decision()->branch(42));
    }

    public function test_a_plan_marker_changes_only_when_the_issue_does(): void
    {
        $first = $this->decision(body: 'original text')->planMarker(42);
        $same = $this->decision(body: 'original text')->planMarker(42);
        $edited = $this->decision(body: 'edited text')->planMarker(42);

        // Re-running on an untouched issue must not post a second plan; an
        // edited issue is worth planning again.
        $this->assertSame($first, $same);
        $this->assertNotSame($first, $edited);
        $this->assertStringStartsWith('<!-- ai-plan:42:', $first);
    }

    public function test_an_implementation_marker_identifies_one_commit(): void
    {
        $marker = IssueAgentDecision::implementationMarker(42, 'abc123def456');

        $this->assertSame('<!-- ai-implementation:42:abc123def456 -->', $marker);
        $this->assertNotSame($marker, IssueAgentDecision::implementationMarker(42, 'other'));
    }

    /** @param array<int, string> $labels */
    private function decision(
        string $title = 'Something is wrong with the log parser',
        string $body = '',
        array $labels = [IssueAgentDecision::LABEL_TRIGGER],
        string $association = 'OWNER',
    ): IssueAgentDecision {
        return new IssueAgentDecision($title, $body, $labels, $association);
    }

    private function planBody(): string
    {
        return implode("\n", [
            '## 概要',
            '',
            'The referer is dropped when the user agent contains a quote.',
            '',
            '## 実装プラン',
            '',
            '1. Widen the combined format pattern.',
            '2. Add a fixture line with an escaped quote.',
            '',
            '## 完了条件',
            '',
            '- `composer check` passes',
            '',
        ]);
    }
}
