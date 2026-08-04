<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Parsers;

use Apkk\LaravelErrorMonitor\Collectors\ApacheErrorLogCollector;
use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\DTO\StackFrameData;
use Apkk\LaravelErrorMonitor\Support\ApplicationFrameDetector;
use Apkk\LaravelErrorMonitor\Support\ServerErrorClassifier;
use DateTimeImmutable;
use DateTimeZone;
use Generator;

/**
 * Parser for the Apache error log.
 *
 * This is where the failures that never reach the application are written: a
 * process killed for exhausting memory, a request that outlived its timeout, a
 * FastCGI transport that gave up, a permission the deploy got wrong. None of
 * them leave a Laravel entry, and some of them are the ones that matter most.
 *
 * An error log states no HTTP status and no environment, so both are derived,
 * and the events say so rather than presenting either as fact.
 */
final class ApacheErrorLogParser implements LogParser
{
    /** Reported as the exception class when the entry names no PHP throwable. */
    public const UNKNOWN_EXCEPTION = 'ServerError';

    /** `[Mon Aug 03 10:11:12.123456 2026] [php:error] [pid 1] rest…` */
    private const ENTRY_PATTERN = '/^\[(?P<time>[^\]]+)\]\s+\[(?P<tag>[^\]]+)\]\s*(?P<rest>.*)$/s';

    /** Leading `[pid 1234:tid 140]`, `(13)Permission denied:` and `[client 1.2.3.4:5]` prefixes. */
    private const PID_PATTERN = '/^\[pid\s+(?P<pid>[^\]]+)\]\s*/';

    private const ERRNO_PATTERN = '/^\((?P<errno>\d+)\)(?P<errtext>[^:]*):\s*/';

    private const CLIENT_PATTERN = '/^\[client\s+(?P<client>[^\]]+)\]\s*/';

    /** `… in /srv/app/app/Foo.php on line 42` or `… in /srv/app/app/Foo.php:42` */
    private const LOCATION_PATTERN = '#\bin\s+(?P<file>/[^\s:]+\.php)(?:\s+on\s+line\s+|:)(?P<line>\d+)#';

    /** `, referer: https://example.invalid/x` */
    private const REFERER_PATTERN = '/,\s*referer:\s*(?P<referer>\S+)\s*$/';

    /** `#0 /srv/app/public/index.php(52): App\Foo->bar()` */
    private const FRAME_PATTERN = '/^#(?P<index>\d+)\s+(?P<file>.+?)\((?P<line>\d+)\):\s*(?P<call>.*)$/m';

    /**
     * @param  ApplicationFrameDetector  $frameDetector  Flags application frames.
     * @param  ServerErrorClassifier  $classifier  Sorts a message into a failure kind.
     * @param  string  $timezone  Apache writes no offset, so this is the timezone assumed.
     * @param  array<int, string>  $levels  Log levels treated as failures; empty accepts every level.
     * @param  string  $environment  Environment recorded on every event; an error log states none.
     */
    public function __construct(
        private readonly ApplicationFrameDetector $frameDetector,
        private readonly ServerErrorClassifier $classifier,
        private readonly string $timezone,
        private readonly array $levels,
        private readonly string $environment,
    ) {}

    public function supports(LogFileData $logFile): bool
    {
        return $logFile->source === ApacheErrorLogCollector::SOURCE;
    }

    /** @return iterable<ErrorEventData> */
    public function parse(LogFileData $logFile): iterable
    {
        if (! is_readable($logFile->path) || ! is_file($logFile->path)) {
            return;
        }

        // gzopen reads plain files too and decompresses as a stream, so a
        // rotated generation never has to be expanded onto disk.
        $handle = gzopen($logFile->path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            $lines = (function () use ($handle): Generator {
                while (($line = gzgets($handle)) !== false) {
                    yield rtrim($line, "\r\n");
                }
            })();

            yield from $this->parseLines($lines);
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
            // An entry owns every following line until the next header, which
            // is how a PHP stack trace stays part of the error that raised it.
            if (preg_match(self::ENTRY_PATTERN, $line) === 1) {
                $event = $this->makeEvent($buffer);

                if ($event instanceof ErrorEventData) {
                    yield $event;
                }

                $buffer = [$line];

                continue;
            }

            if ($buffer !== []) {
                $buffer[] = $line;
            }
        }

        $event = $this->makeEvent($buffer);

        if ($event instanceof ErrorEventData) {
            yield $event;
        }
    }

    /** @param  array<int, string>  $buffer */
    private function makeEvent(array $buffer): ?ErrorEventData
    {
        if ($buffer === []) {
            return null;
        }

        $raw = implode("\n", $buffer);

        if (preg_match(self::ENTRY_PATTERN, $raw, $header) !== 1) {
            return null;
        }

        [$module, $level] = $this->splitTag($header['tag']);

        if ($this->levels !== [] && ! in_array($level, $this->levels, true)) {
            return null;
        }

        $occurredAt = $this->parseTimestamp($header['time']);

        if ($occurredAt === null) {
            return null;
        }

        $prefixes = $this->stripPrefixes($header['rest']);
        $message = $prefixes['message'];
        $referer = $this->extractReferer($message);
        $message = $referer === null ? $message : (string) preg_replace(self::REFERER_PATTERN, '', $message);

        [$category, $guessed] = $this->classifier->classify($message);
        $location = $this->extractLocation($message);

        return new ErrorEventData(
            environment: $this->environment,
            source: ApacheErrorLogCollector::SOURCE,
            occurredAt: $occurredAt,
            exceptionClass: $this->exceptionClass($message),
            message: $this->firstLine($message),
            normalizedMessage: $this->firstLine($message),
            file: $location['file'],
            line: $location['line'],
            method: null,
            route: null,
            // Derived from the category, never reported by the server. Keeping
            // a missing file at 404 is what stops scanner noise from being
            // stored as a server error under the default `status_codes`.
            statusCode: $this->classifier->statusFor($category),
            stackFrames: $this->extractFrames($message),
            fingerprint: '',
            context: array_filter([
                'client_ip' => $prefixes['client'],
                'referer' => $referer,
                'module' => $module,
                'level' => $level,
                'pid' => $prefixes['pid'],
                'errno' => $prefixes['errno'],
            ], static fn (mixed $value): bool => $value !== null),
            metadata: [
                'error_category' => $category,
                'category_source' => $guessed ? 'default' : 'pattern',
                'category_estimated' => $guessed,
                'status_source' => 'error_category',
                'status_estimated' => true,
            ],
        );
    }

    /** `php:error` into module and level; Apache 2.2 wrote the level alone. */
    private function splitTag(string $tag): array
    {
        $position = strpos($tag, ':');

        return $position === false
            ? [null, strtolower(trim($tag))]
            : [strtolower(substr($tag, 0, $position)), strtolower(substr($tag, $position + 1))];
    }

    /**
     * Peel the optional prefixes Apache writes in a varying order.
     *
     * `[pid 1]`, `(13)Permission denied:` and `[client 1.2.3.4:5]` each appear
     * only sometimes, and not always in the same order, which is why they are
     * stripped one at a time instead of being folded into the header pattern.
     *
     * @return array{pid: string|null, errno: string|null, client: string|null, message: string}
     */
    private function stripPrefixes(string $rest): array
    {
        $pid = null;
        $errno = null;
        $errtext = null;
        $client = null;
        $rest = ltrim($rest);

        for ($pass = 0; $pass < 3; $pass++) {
            if ($pid === null && preg_match(self::PID_PATTERN, $rest, $matches) === 1) {
                $pid = $matches['pid'];
                $rest = (string) preg_replace(self::PID_PATTERN, '', $rest, 1);

                continue;
            }

            if ($errno === null && preg_match(self::ERRNO_PATTERN, $rest, $matches) === 1) {
                $errno = $matches['errno'];
                // Held aside rather than put back in front: `[client …]` still
                // follows it, and would stop being strippable.
                $errtext = trim($matches['errtext']);
                $rest = (string) preg_replace(self::ERRNO_PATTERN, '', $rest, 1);

                continue;
            }

            if ($client === null && preg_match(self::CLIENT_PATTERN, $rest, $matches) === 1) {
                $client = $this->clientAddress($matches['client']);
                $rest = (string) preg_replace(self::CLIENT_PATTERN, '', $rest, 1);

                continue;
            }

            break;
        }

        // "Permission denied" is what the classifier reads, so it rejoins the
        // message once the prefixes around it are gone.
        $message = trim($rest);
        $message = $errtext === null || $errtext === '' ? $message : $errtext.': '.$message;

        return ['pid' => $pid, 'errno' => $errno, 'client' => $client, 'message' => $message];
    }

    /** Apache appends the source port; the address alone is what identifies a client. */
    private function clientAddress(string $client): ?string
    {
        $client = trim($client);

        if ($client === '') {
            return null;
        }

        // IPv4 with port, but never an IPv6 address's own colons.
        if (preg_match('/^(?P<ip>(?:\d{1,3}\.){3}\d{1,3}):\d+$/', $client, $matches) === 1) {
            return $matches['ip'];
        }

        return $client;
    }

    /** @return array{file: string|null, line: int|null} */
    private function extractLocation(string $message): array
    {
        if (preg_match(self::LOCATION_PATTERN, $message, $matches) !== 1) {
            return ['file' => null, 'line' => null];
        }

        return ['file' => $matches['file'], 'line' => (int) $matches['line']];
    }

    private function extractReferer(string $message): ?string
    {
        return preg_match(self::REFERER_PATTERN, $message, $matches) === 1 ? $matches['referer'] : null;
    }

    /**
     * Throwable named by the entry, when PHP wrote one into it.
     */
    private function exceptionClass(string $message): string
    {
        if (preg_match('/Uncaught\s+(?P<class>[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:Exception|Error))\b/', $message, $matches) === 1) {
            return ltrim($matches['class'], '\\');
        }

        return self::UNKNOWN_EXCEPTION;
    }

    /** @return array<int, StackFrameData> */
    private function extractFrames(string $message): array
    {
        if (preg_match_all(self::FRAME_PATTERN, $message, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $frames = [];

        foreach ($matches as $match) {
            $call = (string) preg_replace('/\(.*$/s', '', trim($match['call']));

            $frames[] = new StackFrameData(
                file: $match['file'],
                line: (int) $match['line'],
                class: $this->callPart($call, 0),
                function: $this->callPart($call, 1),
                type: str_contains($call, '->') ? '->' : (str_contains($call, '::') ? '::' : null),
                isApplicationFrame: $this->frameDetector->isApplication($match['file']),
            );
        }

        return $frames;
    }

    /** Split `Class->method` into its class (0) and function (1) part. */
    private function callPart(string $call, int $index): ?string
    {
        foreach (['->', '::'] as $type) {
            $position = strpos($call, $type);

            if ($position !== false) {
                return $index === 0
                    ? ltrim(substr($call, 0, $position), '\\')
                    : substr($call, $position + strlen($type));
            }
        }

        return $index === 0 ? null : ($call === '' ? null : $call);
    }

    /** The stack trace belongs to the frames, not to the message. */
    private function firstLine(string $message): string
    {
        $line = strtok($message, "\n");

        return trim($line === false ? $message : $line);
    }

    /** `Mon Aug 03 10:11:12.123456 2026`; Apache writes no offset. */
    private function parseTimestamp(string $timestamp): ?DateTimeImmutable
    {
        $timestamp = trim($timestamp);
        $zone = new DateTimeZone($this->timezone);

        foreach (['D M d H:i:s.u Y', 'D M j H:i:s.u Y', 'D M d H:i:s Y', 'D M j H:i:s Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $timestamp, $zone);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }
}
