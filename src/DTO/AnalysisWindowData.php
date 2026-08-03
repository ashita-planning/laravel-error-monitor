<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Immutable period an analysis run looks at.
 *
 * The window is expressed in the configured timezone; the context seconds widen
 * it on both sides so an entry written just outside a day boundary can still be
 * correlated with the request that produced it.
 */
final readonly class AnalysisWindowData
{
    /** @throws InvalidArgumentException When the window is inverted or the context is negative. */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $timezone = 'UTC',
        public int $contextBeforeSeconds = 0,
        public int $contextAfterSeconds = 0,
    ) {
        if ($from > $to) {
            throw new InvalidArgumentException('An analysis window cannot end before it starts.');
        }

        if ($contextBeforeSeconds < 0 || $contextAfterSeconds < 0) {
            throw new InvalidArgumentException('Context seconds cannot be negative.');
        }
    }

    /** Window covering one whole calendar day, e.g. `2026-08-03`, `today` or `yesterday`. */
    public static function forDate(string $date, string $timezone = 'UTC', int $contextBeforeSeconds = 0, int $contextAfterSeconds = 0): self
    {
        $day = self::parse($date, new DateTimeZone($timezone));

        return new self(
            $day->setTime(0, 0, 0),
            $day->setTime(23, 59, 59),
            $timezone,
            $contextBeforeSeconds,
            $contextAfterSeconds,
        );
    }

    /** Window between two arbitrary timestamps. */
    public static function between(string $from, string $to, string $timezone = 'UTC', int $contextBeforeSeconds = 0, int $contextAfterSeconds = 0): self
    {
        $zone = new DateTimeZone($timezone);

        return new self(self::parse($from, $zone), self::parse($to, $zone), $timezone, $contextBeforeSeconds, $contextAfterSeconds);
    }

    /** Start of the window including the correlation context. */
    public function contextFrom(): DateTimeImmutable
    {
        return $this->from->modify('-'.$this->contextBeforeSeconds.' seconds');
    }

    /** End of the window including the correlation context. */
    public function contextTo(): DateTimeImmutable
    {
        return $this->to->modify('+'.$this->contextAfterSeconds.' seconds');
    }

    /** Whether a timestamp falls inside the window, context included. */
    public function contains(DateTimeInterface $moment): bool
    {
        return $moment >= $this->contextFrom() && $moment <= $this->contextTo();
    }

    /** Stable label used by the run lock and by the command output. */
    public function label(): string
    {
        return $this->from->format('Y-m-d\TH:i:sP').'_'.$this->to->format('Y-m-d\TH:i:sP');
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format(DateTimeInterface::ATOM),
            'to' => $this->to->format(DateTimeInterface::ATOM),
            'timezone' => $this->timezone,
            'context_before_seconds' => $this->contextBeforeSeconds,
            'context_after_seconds' => $this->contextAfterSeconds,
        ];
    }

    /** @throws InvalidArgumentException When the expression is not a date. */
    private static function parse(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('A date expression cannot be empty.');
        }

        try {
            return new DateTimeImmutable($value, $zone);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(sprintf('[%s] is not a valid date expression.', $value), 0, $exception);
        }
    }
}
