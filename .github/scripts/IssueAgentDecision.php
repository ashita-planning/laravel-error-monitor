<?php

declare(strict_types=1);

namespace ErrorMonitor\Workflow;

/**
 * Decides what, if anything, an automated agent may do with an issue.
 *
 * This is separated from the workflow YAML and unit tested because it is the
 * part that can be wrong in expensive ways: letting an implementation run
 * unapproved, or on something nobody should automate. YAML `if:` expressions
 * cannot be tested, and this can.
 *
 * The issue body is untrusted input. Anybody able to open an issue on a public
 * repository can write anything into it, so nothing here treats the text as
 * instructions - it is only ever pattern-matched for structure and for subjects
 * that must not be automated.
 */
final class IssueAgentDecision
{
    /** Do nothing: the issue is not addressed to the agent. */
    public const MODE_NONE = 'none';

    /** Investigate and propose a plan. No file may change. */
    public const MODE_PLAN = 'plan';

    /** Implement the approved plan on a branch. */
    public const MODE_IMPLEMENT = 'implement';

    /** Stop and ask for a human. */
    public const MODE_REVIEW_REQUIRED = 'review-required';

    public const LABEL_TRIGGER = 'ai-fix';

    public const LABEL_APPROVED = 'plan-approved';

    public const LABEL_RUNNING = 'ai-running';

    public const LABEL_DONE = 'ai-done';

    public const LABEL_FAILED = 'ai-failed';

    public const LABEL_REVIEW_REQUIRED = 'plan-review-required';

    /**
     * Subjects that are never implemented automatically.
     *
     * Each is something where a plausible-looking wrong change is worse than no
     * change: money, access, deletion, and anything whose correctness cannot be
     * established from the repository alone. Matched case-insensitively against
     * the title and body in both languages the project uses.
     *
     * @var array<string, array<int, string>>
     */
    private const HIGH_RISK = [
        'authentication' => ['認証', '権限', 'ログイン', 'authentication', 'authorization', 'permission', 'login', 'password', 'session hijack'],
        'payment' => ['決済', '請求', '課金', '返金', 'payment', 'billing', 'invoice', 'refund', 'charge card'],
        'loyalty' => ['ポイント', '会員ランク', 'マイル', 'loyalty', 'reward point', 'membership rank'],
        'production-data' => ['本番データ', '本番DB', 'production data', 'production database', 'data repair', 'backfill production'],
        'data-deletion' => ['データ削除', '削除処理', 'データを消', 'data deletion', 'delete records', 'drop table', 'truncate'],
        'migration' => ['マイグレーション', 'migration', 'schema change', 'alter table'],
        'security' => ['セキュリティ', '脆弱性', '情報漏洩', 'security', 'vulnerability', 'cve-', 'xss', 'sql injection', 'csrf'],
        'unknown-external-api' => ['外部API仕様不明', '仕様不明', 'api仕様が不明', 'undocumented api', 'unknown api'],
        'not-reproducible' => ['再現できない', '再現しない', '再現性がない', 'cannot reproduce', 'not reproducible', 'intermittent'],
        'major-dependency' => ['メジャー更新', 'メジャーバージョン', 'major update', 'major version upgrade', 'breaking upgrade'],
    ];

    /** Headings that together make a body a plan rather than a wish. */
    private const PLAN_HEADING = '/^#{1,3}\s*(実装プラン|実装計画|implementation plan)\s*$/mu';

    private const COMPLETION_HEADING = '/^#{1,3}\s*(完了条件|受け入れ条件|acceptance criteria|definition of done)\s*$/mu';

    private const TARGET_HEADING = '/^#{1,3}\s*(対象|変更対象|対象ファイル|scope|files)\s*$/mu';

    /** A numbered or bulleted step. */
    private const STEP = '/^\s*(?:\d+\.|[-*+])\s+\S/mu';

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, string>  $comments
     */
    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly array $labels,
        private readonly string $authorAssociation,
        private readonly array $comments = [],
        private readonly string $eventAction = 'opened',
        private readonly ?string $eventLabel = null,
    ) {}

    /**
     * Only human workflow labels may start agent work.
     *
     * The workflow adds status labels of its own. Those additions emit another
     * `issues.labeled` event, so accepting every label event would run the
     * agent again and spend API credit for work it had already started.
     */
    public function eventCanTrigger(): bool
    {
        if ($this->eventAction === 'opened') {
            return true;
        }

        if ($this->eventAction !== 'labeled' || $this->eventLabel === null) {
            return false;
        }

        return in_array(strtolower(trim($this->eventLabel)), [self::LABEL_TRIGGER, self::LABEL_APPROVED], true);
    }

    /**
     * Whether the actor is trusted enough for the agent to run at all.
     *
     * Anybody can open an issue on a public repository, so the gate is the
     * person rather than the text. `OWNER`, `MEMBER` and `COLLABORATOR` are the
     * associations GitHub gives to people with repository access.
     */
    public function actorIsTrusted(): bool
    {
        return in_array(strtoupper($this->authorAssociation), ['OWNER', 'MEMBER', 'COLLABORATOR'], true);
    }

    public function hasTriggerLabel(): bool
    {
        return $this->hasLabel(self::LABEL_TRIGGER);
    }

    public function isApproved(): bool
    {
        return $this->hasLabel(self::LABEL_APPROVED);
    }

    /**
     * Whether the body describes a plan concrete enough to act on.
     *
     * A heading alone is not enough: a plan has to say what it will change and
     * how anyone would know it worked, or "implement this" means whatever the
     * agent decides it means.
     */
    public function hasPlan(): bool
    {
        foreach ($this->planDocuments() as $document) {
            if ($this->documentHasPlan($document)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Subjects in this issue that must not be implemented automatically.
     *
     * @return array<int, string>
     */
    public function highRiskCategories(): array
    {
        $planComments = array_values(array_filter($this->comments, $this->documentHasPlan(...)));
        $haystack = mb_strtolower(implode("\n", [$this->title, $this->body, ...$planComments]));
        $found = [];

        foreach (self::HIGH_RISK as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, mb_strtolower($needle))) {
                    $found[] = $category;

                    break;
                }
            }
        }

        return $found;
    }

    public function isHighRisk(): bool
    {
        return $this->highRiskCategories() !== [];
    }

    /**
     * What the agent may do.
     *
     * The order matters. Trust first, because an untrusted actor gets nothing
     * at all. Risk before approval, because a `plan-approved` label must not be
     * able to authorise automating a payment change - a label is a person's
     * judgement about a plan, not a waiver on the subject.
     */
    public function mode(): string
    {
        if (! $this->eventCanTrigger() || ! $this->actorIsTrusted() || ! $this->hasTriggerLabel()) {
            return self::MODE_NONE;
        }

        if ($this->isHighRisk()) {
            return self::MODE_REVIEW_REQUIRED;
        }

        if ($this->hasPlan() && $this->isApproved()) {
            return self::MODE_IMPLEMENT;
        }

        return self::MODE_PLAN;
    }

    /** Why the agent reached that decision, in one line fit for a comment. */
    public function reason(): string
    {
        return match ($this->mode()) {
            self::MODE_NONE => $this->noneReason(),
            self::MODE_REVIEW_REQUIRED => sprintf(
                'This issue touches %s, which is never implemented automatically.',
                implode(', ', $this->highRiskCategories()),
            ),
            self::MODE_IMPLEMENT => 'The plan is present and approved.',
            default => $this->hasPlan()
                ? sprintf('The plan is present but not yet approved with `%s`.', self::LABEL_APPROVED)
                : 'The issue has no implementation plan yet.',
        };
    }

    private function noneReason(): string
    {
        if (! $this->eventCanTrigger()) {
            return 'This event does not start agent work.';
        }

        return $this->actorIsTrusted()
            ? sprintf('The issue does not carry the `%s` label.', self::LABEL_TRIGGER)
            : 'The issue was raised by somebody without write access to this repository.';
    }

    /** Branch an implementation is allowed to use, and no other. */
    public function branch(int $issueNumber): string
    {
        return sprintf('ai/issue-%d', $issueNumber);
    }

    /**
     * Marker identifying a plan comment for one revision of one issue.
     *
     * The hash is over the body, so editing the issue makes a new plan worth
     * posting and leaving it alone does not.
     */
    public function planMarker(int $issueNumber): string
    {
        return sprintf('<!-- ai-plan:%d:%s -->', $issueNumber, substr(hash('sha256', $this->body), 0, 16));
    }

    /** Marker identifying an implementation comment for one commit. */
    public static function implementationMarker(int $issueNumber, string $commitSha): string
    {
        return sprintf('<!-- ai-implementation:%d:%s -->', $issueNumber, substr($commitSha, 0, 40));
    }

    private function hasLabel(string $label): bool
    {
        foreach ($this->labels as $candidate) {
            if (strcasecmp(trim($candidate), $label) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function planDocuments(): array
    {
        return [$this->body, ...$this->comments];
    }

    private function documentHasPlan(string $document): bool
    {
        if (preg_match(self::PLAN_HEADING, $document) !== 1) {
            return false;
        }

        if (preg_match(self::STEP, $this->planSection($document)) !== 1) {
            return false;
        }

        return preg_match(self::COMPLETION_HEADING, $document) === 1;
    }

    /** The text under the plan heading, up to the next heading. */
    private function planSection(string $document): string
    {
        if (preg_match('/^#{1,3}\s*(?:実装プラン|実装計画|implementation plan)\s*$(?P<body>.*?)(?=^#{1,3}\s|\z)/musi', $document, $matches) !== 1) {
            return '';
        }

        return $matches['body'];
    }
}
