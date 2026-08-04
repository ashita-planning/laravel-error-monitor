<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Parsers;

use Apkk\LaravelErrorMonitor\Collectors\LaravelLogCollector;
use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\DTO\StackFrameData;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Apkk\LaravelErrorMonitor\Support\ApplicationFrameDetector;
use Apkk\LaravelErrorMonitor\Support\HttpStatusResolver;
use DateTimeImmutable;
use DateTimeZone;
use Generator;

/**
 * Parser for the Monolog line format Laravel writes by default.
 *
 * An entry starts with `[Y-m-d H:i:s] env.LEVEL: message` and owns every
 * following line until the next entry header, which is where the `[stacktrace]`
 * block and the JSON context live.
 *
 * The parser never masks and never fingerprints: it reports what the log says
 * and {@see ErrorMonitorAnalyzer::prepare()} masks before anything is
 * normalized, fingerprinted or stored.
 */
final class LaravelLogParser implements LogParser
{
    /** Reported when the entry carries no recoverable throwable class. */
    public const UNKNOWN_EXCEPTION = 'UnknownException';

    private const ENTRY_PATTERN = '/^\[(?P<timestamp>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\]\s*(?P<environment>[^\s.]+)\.(?P<level>[A-Z]+):\s?(?P<message>.*)$/';

    // The code is not always numeric: a QueryException reports "(code: 42S02)".
    private const EXCEPTION_PATTERN = '/\((?P<class>[A-Za-z0-9_\\\\]+)\(code:\s*[^)]*\):\s*(?P<message>.*?)\s+at\s+(?P<file>\S+?):(?P<line>\d+)\)/s';

    private const FRAME_PATTERN = '/^#(?P<index>\d+)\s+(?P<file>.+?)\((?P<line>\d+)\):\s*(?P<call>.*)$/';

    private const INTERNAL_FRAME_PATTERN = '/^#(?P<index>\d+)\s+\[internal function\]:\s*(?P<call>.*)$/';

    /**
     * @param  ApplicationFrameDetector  $frameDetector  Flags application frames.
     * @param  HttpStatusResolver  $statusResolver  Derives the HTTP status from the throwable.
     * @param  string  $timezone  Timezone assumed for timestamps written without an offset.
     * @param  array<int, string>  $levels  Log levels treated as failures; an empty list accepts every level.
     * @param  string|null  $environment  Overrides the environment written in the log entry.
     */
    public function __construct(
        private readonly ApplicationFrameDetector $frameDetector,
        private readonly HttpStatusResolver $statusResolver,
        private readonly string $timezone,
        private readonly array $levels,
        private readonly ?string $environment = null,
    ) {}

    public function supports(LogFileData $logFile): bool
    {
        return $logFile->source === LaravelLogCollector::SOURCE;
    }

    /** @return iterable<ErrorEventData> */
    public function parse(LogFileData $logFile): iterable
    {
        if (! is_readable($logFile->path) || ! is_file($logFile->path)) {
            return;
        }

        $handle = fopen($logFile->path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            // The file is streamed line by line so a multi-gigabyte log never
            // has to fit in memory.
            $lines = (function () use ($handle): Generator {
                while (($line = fgets($handle)) !== false) {
                    yield rtrim($line, "\r\n");
                }
            })();

            yield from $this->parseLines($lines);
        } finally {
            fclose($handle);
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
        return $this->parseLines(preg_split('/\r\n|\n|\r/', $content) ?: []);
    }

    /**
     * @param  iterable<int, string>  $lines
     * @return Generator<int, ErrorEventData>
     */
    private function parseLines(iterable $lines): Generator
    {
        /** @var array<int, string> $buffer */
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match(self::ENTRY_PATTERN, $line) === 1) {
                $event = $this->makeEvent($buffer);

                if ($event instanceof ErrorEventData) {
                    yield $event;
                }

                $buffer = [$line];

                continue;
            }

            // Anything before the first header - a truncated entry at the top
            // of a rotated file - belongs to no entry and is dropped.
            if ($buffer !== []) {
                $buffer[] = $line;
            }
        }

        $event = $this->makeEvent($buffer);

        if ($event instanceof ErrorEventData) {
            yield $event;
        }
    }

    /**
     * Turn one buffered log entry into an event.
     *
     * @param  array<int, string>  $buffer
     */
    private function makeEvent(array $buffer): ?ErrorEventData
    {
        if ($buffer === []) {
            return null;
        }

        if (preg_match(self::ENTRY_PATTERN, $buffer[0], $header) !== 1) {
            return null;
        }

        $level = strtoupper($header['level']);

        if ($this->levels !== [] && ! in_array($level, $this->levels, true)) {
            return null;
        }

        $raw = implode("\n", $buffer);
        $context = $this->extractContext($raw);
        $exception = $this->extractException($raw);
        $message = $this->extractMessage($header['message']);
        $message = $message !== '' ? $message : ($exception['message'] ?? '');

        $exceptionClass = $exception['class'] ?? null;
        [$statusCode, $statusSource] = $this->resolveStatus($exceptionClass, $context);

        return new ErrorEventData(
            environment: $this->environment ?? $header['environment'],
            source: LaravelLogCollector::SOURCE,
            occurredAt: $this->parseTimestamp($header['timestamp']),
            exceptionClass: $exceptionClass ?? self::UNKNOWN_EXCEPTION,
            message: $message,
            // The analyzer masks first and normalizes the masked value, so the
            // raw message is only a placeholder until then.
            normalizedMessage: $message,
            file: $exception['file'] ?? null,
            line: isset($exception['line']) ? (int) $exception['line'] : null,
            method: $this->extractHttpMethod($context),
            route: $this->extractRoute($context),
            statusCode: $statusCode,
            stackFrames: $this->extractFrames($buffer),
            fingerprint: '',
            context: array_merge($context, ['level' => $level]),
            metadata: [
                'log_level' => $level,
                'status_source' => $statusSource,
                'status_estimated' => $statusSource === 'assumed',
            ],
        );
    }

    /**
     * Resolve the HTTP status, remembering how confident we are about it.
     *
     * An explicit status in the log context wins, a mapped throwable comes
     * second, and everything else is an assumption flagged as such: an uncaught
     * exception usually - but not always - answers 500, and the `status_codes`
     * filter must not silently turn that guess into a fact.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: int, 1: string}
     */
    private function resolveStatus(?string $exceptionClass, array $context): array
    {
        foreach (['status', 'status_code', 'http_status'] as $key) {
            $value = $context[$key] ?? null;

            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $status = (int) $value;

                if ($status >= 100 && $status <= 599) {
                    return [$status, 'context'];
                }
            }
        }

        $mapped = $this->statusResolver->mapped($exceptionClass);

        if ($mapped !== null) {
            return [$mapped, 'exception_class'];
        }

        return [$this->statusResolver->resolve(null), 'assumed'];
    }

    /** Strip the JSON context appended to the message line. */
    private function extractMessage(string $message): string
    {
        $position = strpos($message, ' {"');

        if ($position !== false) {
            $message = substr($message, 0, $position);
        }

        return trim($message);
    }

    /**
     * Decode the JSON context when it is valid JSON.
     *
     * Laravel writes exception traces with real line breaks inside the JSON
     * string, which makes that context undecodable; the exception details are
     * recovered by {@see extractException()} in that case.
     *
     * @return array<string, mixed>
     */
    private function extractContext(string $raw): array
    {
        $position = strpos($raw, ' {"');

        if ($position === false) {
            return [];
        }

        $decoded = json_decode(trim(substr($raw, $position)), true);

        if (! is_array($decoded)) {
            return [];
        }

        // The serialized throwable is already represented by the event itself.
        unset($decoded['exception']);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array{class?: string, message?: string, file?: string, line?: string} */
    private function extractException(string $raw): array
    {
        if (preg_match(self::EXCEPTION_PATTERN, $raw, $matches) !== 1) {
            return [];
        }

        return [
            // The context is JSON encoded, so namespace separators are doubled.
            'class' => ltrim(str_replace('\\\\', '\\', $matches['class']), '\\'),
            'message' => trim($matches['message']),
            'file' => $matches['file'],
            'line' => $matches['line'],
        ];
    }

    /**
     * @param  array<int, string>  $buffer
     * @return array<int, StackFrameData>
     */
    private function extractFrames(array $buffer): array
    {
        $frames = [];

        foreach ($buffer as $line) {
            $line = trim($line);

            if (preg_match(self::FRAME_PATTERN, $line, $matches) === 1) {
                $file = str_replace('\\\\', '\\', $matches['file']);
                $call = $this->splitCall($matches['call']);

                $frames[] = new StackFrameData(
                    file: $file,
                    line: (int) $matches['line'],
                    class: $call['class'],
                    function: $call['function'],
                    type: $call['type'],
                    isApplicationFrame: $this->frameDetector->isApplication($file),
                );

                continue;
            }

            if (preg_match(self::INTERNAL_FRAME_PATTERN, $line, $matches) === 1) {
                $call = $this->splitCall($matches['call']);

                $frames[] = new StackFrameData(
                    file: null,
                    line: null,
                    class: $call['class'],
                    function: $call['function'],
                    type: $call['type'],
                );
            }
        }

        return $frames;
    }

    /**
     * Split `Class->method(...)` into its parts.
     *
     * @return array{class: string|null, function: string|null, type: string|null}
     */
    private function splitCall(string $call): array
    {
        $call = str_replace('\\\\', '\\', trim($call));
        $call = (string) preg_replace('/\(.*$/s', '', $call);

        if ($call === '') {
            return ['class' => null, 'function' => null, 'type' => null];
        }

        foreach (['->', '::'] as $type) {
            $position = strpos($call, $type);

            if ($position !== false) {
                return [
                    'class' => ltrim(substr($call, 0, $position), '\\'),
                    'function' => substr($call, $position + strlen($type)),
                    'type' => $type,
                ];
            }
        }

        return ['class' => null, 'function' => $call, 'type' => null];
    }

    /** @param  array<string, mixed>  $context */
    private function extractHttpMethod(array $context): ?string
    {
        foreach (['method', 'http_method', 'request_method'] as $key) {
            $value = $context[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return strtoupper($value);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $context */
    private function extractRoute(array $context): ?string
    {
        foreach (['route', 'uri', 'url', 'path'] as $key) {
            $value = $context[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function parseTimestamp(string $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamp, new DateTimeZone($this->timezone));
    }
}
