<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Support;

/**
 * Source keys the package knows by name.
 *
 * Constants rather than an enum on purpose: a source key is an open set. An
 * adapter package adds `xserver_apache_access` or `s3_apache_error` without
 * being able to add a case to an enum declared here, and the parsers match on
 * the string either way.
 */
final class LogSource
{
    public const LARAVEL = 'laravel';

    public const APACHE_ACCESS = 'apache_access';

    public const APACHE_ERROR = 'apache_error';

    /**
     * Identifier of the collector that fronts externally supplied files.
     *
     * It is not a source key of its own: the files it yields carry the real
     * one, which is what a parser claims them by.
     */
    public const SERVER_LOG_SOURCES = 'server_log_sources';

    /**
     * Source keys with a bundled parser.
     *
     * @return array<int, string>
     */
    public static function bundled(): array
    {
        return [self::LARAVEL, self::APACHE_ACCESS, self::APACHE_ERROR];
    }
}
