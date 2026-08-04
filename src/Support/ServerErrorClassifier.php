<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Support;

/**
 * Sorts a web server error message into the kind of failure it describes.
 *
 * An Apache error log mixes things that need very different responses: a
 * process killed for exhausting memory, a request that outlived its timeout, a
 * permission the deploy got wrong, and the steady background noise of scanners
 * asking for files that do not exist. The category is what makes the difference
 * visible before anyone reads the message.
 *
 * Rules are ordered from specific to general, because the specific ones are
 * genuinely more useful: an exhausted memory limit is also a PHP fatal error,
 * but "the limit is too low" is the actionable statement.
 */
final class ServerErrorClassifier
{
    public const PHP_FATAL = 'php_fatal';

    public const MEMORY_EXHAUSTED = 'memory_exhausted';

    public const TIMEOUT = 'timeout';

    public const PERMISSION = 'permission';

    public const FASTCGI = 'fastcgi';

    public const CONFIGURATION = 'configuration';

    public const MISSING_FILE = 'missing_file';

    public const SERVER_INTERNAL = 'server_internal';

    public const UNKNOWN = 'unknown';

    /**
     * Ordered patterns, first match wins.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const RULES = [
        // Both of these are also PHP fatal errors, but "the limit is too low"
        // and "the request took too long" are the statements worth acting on.
        [self::MEMORY_EXHAUSTED, '/allowed memory size of \d+ bytes exhausted|out of memory|cannot allocate memory/i'],
        [self::TIMEOUT, '/maximum execution time of \d+ seconds? exceeded|script timed out|read data timeout|request timed? ?out|idle timeout|gateway time-?out|AH01075|AH00957/i'],
        // Ahead of the transport rules on purpose: an AH01071 or a "FastCGI
        // sent in stderr" that quotes a PHP fatal is a bug in the application,
        // not in the transport that reported it.
        [self::PHP_FATAL, '/php fatal error|uncaught (?:exception|error)|php parse error|php startup|call to (?:a member function|undefined)/i'],
        [self::PERMISSION, '/permission denied|client denied by server configuration|access forbidden|AH01797|AH00035/i'],
        [self::FASTCGI, '/fastcgi sent in stderr|proxy_fcgi|mod_fcgid|premature end of script headers|end of script output before headers|AH01071|AH01067|AH00898/i'],
        [self::CONFIGURATION, '/\.htaccess|invalid command|syntax error on line|configuration error|directoryindex|AH00124|AH00526/i'],
        [self::MISSING_FILE, '/file does not exist|script not found or unable to stat|no such file or directory|AH00128/i'],
        [self::SERVER_INTERNAL, '/child (?:process|pid) \d+|exit signal|segmentation fault|seg fault|caught sig|AH00052|AH00051/i'],
    ];

    /**
     * HTTP status each category stands for.
     *
     * An error log states no status, so this is derived and every event says so
     * through `status_estimated`. It matters because it is what keeps the
     * background noise out: `status_codes` defaults to 500, so a scanner asking
     * for a file that does not exist is parsed and then simply not stored.
     *
     * @var array<string, int>
     */
    private const STATUS = [
        self::MISSING_FILE => 404,
        self::PERMISSION => 403,
    ];

    /** Status assumed for a category that maps to no better answer. */
    public const DEFAULT_STATUS = 500;

    /**
     * Category the message belongs to.
     *
     * @return array{0: string, 1: bool} Category, and whether it had to be guessed.
     */
    public function classify(string $message): array
    {
        foreach (self::RULES as [$category, $pattern]) {
            if (preg_match($pattern, $message) === 1) {
                return [$category, false];
            }
        }

        return [self::UNKNOWN, true];
    }

    /** HTTP status the category stands for. Always an estimate. */
    public function statusFor(string $category): int
    {
        return self::STATUS[$category] ?? self::DEFAULT_STATUS;
    }
}
