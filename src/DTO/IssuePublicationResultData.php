<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

use InvalidArgumentException;

/**
 * What an issue tracker did with a report.
 *
 * The identifier is a string rather than a number because not every tracker
 * counts: GitHub gives out `1234`, Jira gives out `OPS-42`, and a contract that
 * assumed the former would have to be broken for the latter.
 *
 * `skipped` is an outcome, not a severity. "This failure was already reported
 * today" is the single most common thing a daily run has to say, and treating
 * it as a warning would drown the ones that matter.
 */
final readonly class IssuePublicationResultData
{
    /** A new issue was opened. */
    public const ACTION_CREATED = 'created';

    /** A further occurrence was reported on an existing issue. */
    public const ACTION_COMMENTED = 'commented';

    /** A closed issue was opened again because the failure came back. */
    public const ACTION_REOPENED = 'reopened';

    /** Already reported; nothing needed doing. */
    public const ACTION_SKIPPED = 'skipped';

    /** The tracker could not be told. */
    public const ACTION_FAILED = 'failed';

    private const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_COMMENTED,
        self::ACTION_REOPENED,
        self::ACTION_SKIPPED,
        self::ACTION_FAILED,
    ];

    /**
     * @param  string  $externalId  The tracker's own identifier, e.g. `1234` or `OPS-42`.
     * @param  string  $state  The tracker's own state, e.g. `open` or `closed`.
     * @param  string  $action  One of the ACTION_* constants.
     * @param  array<string, mixed>  $metadata  Anything else the adapter wants remembered.
     *
     * @throws InvalidArgumentException When the action is not one this package knows.
     */
    public function __construct(
        public string $externalId,
        public string $state,
        public string $action,
        public ?string $url = null,
        public array $metadata = [],
    ) {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(sprintf('[%s] is not a known publication action.', $action));
        }
    }

    /** Nothing was published, and nothing was wrong. */
    public static function skipped(string $externalId = '', string $state = ''): self
    {
        return new self($externalId, $state, self::ACTION_SKIPPED);
    }

    /**
     * The tracker could not be told.
     *
     * The reason is a message the adapter chose. Adapters must not put a token
     * or a raw response body in it.
     */
    public static function failure(string $reason): self
    {
        return new self('', '', self::ACTION_FAILED, metadata: ['reason' => $reason]);
    }

    /** Whether the tracker now holds something it did not before. */
    public function changedAnything(): bool
    {
        return in_array($this->action, [self::ACTION_CREATED, self::ACTION_COMMENTED, self::ACTION_REOPENED], true);
    }

    public function failed(): bool
    {
        return $this->action === self::ACTION_FAILED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'state' => $this->state,
            'action' => $this->action,
            'url' => $this->url,
            'metadata' => $this->metadata,
        ];
    }
}
