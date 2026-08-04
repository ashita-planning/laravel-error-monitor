<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Parsers;

use Apkk\LaravelErrorMonitor\Collectors\ApacheAccessLogCollector;
use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use DateTimeImmutable;
use DateTimeZone;
use Generator;

/**
 * Parser for the Apache Common and Combined log formats.
 *
 * An access log says what the server answered, not why. A 502 or a 503 never
 * reaches PHP at all and therefore leaves no Laravel entry, which is exactly why
 * this source is worth reading: it sees the failures the application log cannot.
 *
 * Only the status codes in range are turned into events - a log of successful
 * traffic would otherwise dwarf everything else - and the status here is a fact
 * reported by the server, never an assumption, which the events record as
 * `metadata.status_source = access_log`.
 */
final class ApacheAccessLogParser implements LogParser
{
    /** Reported as the exception class: an access log names no throwable. */
    public const UNKNOWN_EXCEPTION = 'HttpServerError';

    /**
     * Common Log Format: `%h %l %u %t "%r" %>s %b`.
     *
     * The request line is quoted but not otherwise escaped, so a request with a
     * quote in it is matched lazily up to the closing quote before the status.
     */
    private const COMMON_PATTERN = <<<'REGEX'
        /^(?P<client>\S+)\s+(?P<identity>\S+)\s+(?P<user>\S+)\s+\[(?P<time>[^\]]+)\]\s+"(?P<request>(?:[^"\\]|\\.)*)"\s+(?P<status>\d{3})\s+(?P<bytes>\d+|-)\s*$/
        REGEX;

    /** Combined Log Format: Common plus `"%{Referer}i" "%{User-Agent}i"`. */
    private const COMBINED_PATTERN = <<<'REGEX'
        /^(?P<client>\S+)\s+(?P<identity>\S+)\s+(?P<user>\S+)\s+\[(?P<time>[^\]]+)\]\s+"(?P<request>(?:[^"\\]|\\.)*)"\s+(?P<status>\d{3})\s+(?P<bytes>\d+|-)\s+"(?P<referer>(?:[^"\\]|\\.)*)"\s+"(?P<agent>(?:[^"\\]|\\.)*)"(?P<trailing>.*)$/
        REGEX;

    /** `GET /orders/12?x=1 HTTP/1.1` */
    private const REQUEST_PATTERN = '#^(?P<method>[A-Z]+)\s+(?P<path>\S+)(?:\s+(?P<protocol>HTTP/[\d.]+))?$#';

    /**
     * @param  string  $timezone  Timezone assumed when a timestamp carries no offset.
     * @param  array<int, array{0: int, 1: int}>  $statusRanges  Inclusive status ranges turned into events.
     * @param  array<int, string>  $patterns  Extra regexes with named groups, tried before the built-in formats.
     * @param  string  $environment  Environment recorded on every event; an access log states none.
     */
    public function __construct(
        private readonly string $timezone,
        private readonly array $statusRanges,
        private readonly array $patterns,
        private readonly string $environment,
    ) {}

    public function supports(LogFileData $logFile): bool
    {
        return $logFile->source === ApacheAccessLogCollector::SOURCE;
    }

    /** @return iterable<ErrorEventData> */
    public function parse(LogFileData $logFile): iterable
    {
        if (! is_readable($logFile->path) || ! is_file($logFile->path)) {
            return;
        }

        // gzopen reads plain files too, and decompresses without ever writing an
        // expanded copy anywhere - there is no temporary file to secure.
        $handle = gzopen($logFile->path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            while (($line = gzgets($handle)) !== false) {
                $event = $this->makeEvent(rtrim($line, "\r\n"));

                if ($event instanceof ErrorEventData) {
                    yield $event;
                }
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * Parse an already loaded chunk of log content.
     *
     * Not part of the {@see LogParser} contract: it exists for tests and for
     * future collectors that stream remote logs into memory.
     *
     * @return iterable<ErrorEventData>
     */
    public function parseContent(string $content): iterable
    {
        return (function () use ($content): Generator {
            foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
                $event = $this->makeEvent($line);

                if ($event instanceof ErrorEventData) {
                    yield $event;
                }
            }
        })();
    }

    private function makeEvent(string $line): ?ErrorEventData
    {
        if (trim($line) === '') {
            return null;
        }

        $fields = $this->match($line);

        if ($fields === null) {
            // A malformed line is skipped: one broken entry must not cost the
            // rest of the file.
            return null;
        }

        $status = (int) $fields['status'];

        if (! $this->inRange($status)) {
            return null;
        }

        $occurredAt = $this->parseTimestamp($fields['time'] ?? '');

        if ($occurredAt === null) {
            return null;
        }

        $request = $this->parseRequest($fields['request'] ?? '');
        $path = $this->stripQueryString($request['path']);

        return new ErrorEventData(
            environment: $this->environment,
            source: ApacheAccessLogCollector::SOURCE,
            occurredAt: $occurredAt,
            exceptionClass: self::UNKNOWN_EXCEPTION,
            message: sprintf('HTTP %d for %s %s', $status, $request['method'] ?? '-', $path ?? '-'),
            normalizedMessage: sprintf('HTTP %d for %s %s', $status, $request['method'] ?? '-', $path ?? '-'),
            file: null,
            line: null,
            method: $request['method'],
            route: $path,
            statusCode: $status,
            stackFrames: [],
            fingerprint: '',
            // Everything here is masked by the analyzer before it is stored, so
            // the client address never reaches the database in the clear.
            context: array_filter([
                'client_ip' => $this->nullableField($fields, 'client'),
                'referer' => $this->nullableField($fields, 'referer'),
                'user_agent' => $this->nullableField($fields, 'agent'),
                'protocol' => $request['protocol'],
                'response_bytes' => $this->responseBytes($fields),
                'request_id' => $this->nullableField($fields, 'request_id'),
                'request_time' => $this->nullableField($fields, 'request_time'),
            ], static fn (mixed $value): bool => $value !== null),
            metadata: [
                // The server reported this status; nothing is being guessed.
                'status_source' => 'access_log',
                'status_estimated' => false,
            ],
        );
    }

    /**
     * First pattern that recognises the line, custom patterns first.
     *
     * @return array<string, string>|null
     */
    private function match(string $line): ?array
    {
        foreach ([...$this->patterns, self::COMBINED_PATTERN, self::COMMON_PATTERN] as $pattern) {
            if (@preg_match($pattern, $line, $matches) === 1) {
                /** @var array<string, string> $named */
                $named = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                if (isset($named['status'])) {
                    return $named;
                }
            }
        }

        return null;
    }

    /** @param  array<string, string>  $fields */
    private function nullableField(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        // Apache writes `-` for a field it has no value for.
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        return stripslashes($value);
    }

    /** @param  array<string, string>  $fields */
    private function responseBytes(array $fields): ?int
    {
        $bytes = $fields['bytes'] ?? null;

        return $bytes === null || ! ctype_digit($bytes) ? null : (int) $bytes;
    }

    /** @return array{method: string|null, path: string|null, protocol: string|null} */
    private function parseRequest(string $request): array
    {
        $request = stripslashes(trim($request));

        if (preg_match(self::REQUEST_PATTERN, $request, $matches) !== 1) {
            return ['method' => null, 'path' => $request === '' ? null : $request, 'protocol' => null];
        }

        return [
            'method' => strtoupper($matches['method']),
            'path' => $matches['path'],
            'protocol' => ($matches['protocol'] ?? '') === '' ? null : $matches['protocol'],
        ];
    }

    /**
     * Drop the query string before the value goes anywhere.
     *
     * The masker would remove it later too, but an access log is the one source
     * where a token in a URL is routine, so it is cut at the source rather than
     * carried one step further than it has to be.
     */
    private function stripQueryString(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return (string) preg_replace('/[?#].*$/', '', $path);
    }

    private function inRange(int $status): bool
    {
        foreach ($this->statusRanges as [$from, $to]) {
            if ($status >= $from && $status <= $to) {
                return true;
            }
        }

        return false;
    }

    /** Apache writes `03/Aug/2026:10:11:12 +0900`; the offset is authoritative. */
    private function parseTimestamp(string $timestamp): ?DateTimeImmutable
    {
        $timestamp = trim($timestamp);

        if ($timestamp === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s P', $timestamp)
            ?: DateTimeImmutable::createFromFormat('d/M/Y:H:i:s', $timestamp, new DateTimeZone($this->timezone));

        return $parsed === false ? null : $parsed;
    }
}
