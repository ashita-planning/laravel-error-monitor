<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\Support\ServerErrorClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerErrorClassifierTest extends TestCase
{
    #[DataProvider('messages')]
    public function test_it_sorts_a_message_into_its_kind(string $message, string $expected): void
    {
        [$category, $guessed] = (new ServerErrorClassifier)->classify($message);

        $this->assertSame($expected, $category);
        $this->assertFalse($guessed, 'A matched rule is not a guess.');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function messages(): array
    {
        return [
            'memory limit' => [
                'PHP Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
                ServerErrorClassifier::MEMORY_EXHAUSTED,
            ],
            'execution time' => [
                'PHP Fatal error: Maximum execution time of 30 seconds exceeded',
                ServerErrorClassifier::TIMEOUT,
            ],
            'fcgid read timeout' => [
                'mod_fcgid: read data timeout in 45 seconds',
                ServerErrorClassifier::TIMEOUT,
            ],
            'uncaught throwable' => [
                'PHP Fatal error: Uncaught TypeError: bad argument',
                ServerErrorClassifier::PHP_FATAL,
            ],
            'php startup' => [
                "PHP Startup: Unable to load dynamic library 'redis.so'",
                ServerErrorClassifier::PHP_FATAL,
            ],
            'denied by configuration' => [
                'AH01797: client denied by server configuration: /srv/app/public/.env',
                ServerErrorClassifier::PERMISSION,
            ],
            'permission denied' => [
                'Permission denied: AH00035: access to /admin denied',
                ServerErrorClassifier::PERMISSION,
            ],
            'premature end of headers' => [
                'Premature end of script headers: index.php',
                ServerErrorClassifier::FASTCGI,
            ],
            'fastcgi stderr' => [
                'FastCGI sent in stderr: "Primary script unknown"',
                ServerErrorClassifier::FASTCGI,
            ],
            'htaccess' => [
                "/srv/app/public/.htaccess: Invalid command 'RewriteEngin'",
                ServerErrorClassifier::CONFIGURATION,
            ],
            'missing file' => [
                'File does not exist: /srv/app/public/favicon.ico',
                ServerErrorClassifier::MISSING_FILE,
            ],
            'child process' => [
                'AH00052: child pid 9012 exit signal Segmentation fault (11)',
                ServerErrorClassifier::SERVER_INTERNAL,
            ],
        ];
    }

    public function test_a_quoted_php_fatal_beats_the_transport_that_reported_it(): void
    {
        // An AH01071 is a proxy_fcgi message, but the bug is in the application
        // it quotes, which is what someone reading the category needs to know.
        [$category] = (new ServerErrorClassifier)->classify(
            "AH01071: Got error 'PHP message: PHP Fatal error: Uncaught RuntimeException: boom'",
        );

        $this->assertSame(ServerErrorClassifier::PHP_FATAL, $category);
    }

    public function test_an_exhausted_limit_beats_the_fatal_error_it_also_is(): void
    {
        // Every memory exhaustion is a PHP fatal error too; "the limit is too
        // low" is the statement worth acting on.
        [$category] = (new ServerErrorClassifier)->classify(
            'PHP Fatal error: Allowed memory size of 134217728 bytes exhausted',
        );

        $this->assertSame(ServerErrorClassifier::MEMORY_EXHAUSTED, $category);
    }

    public function test_an_unrecognised_message_says_it_was_guessed(): void
    {
        [$category, $guessed] = (new ServerErrorClassifier)->classify('AH99999: something entirely new');

        $this->assertSame(ServerErrorClassifier::UNKNOWN, $category);
        $this->assertTrue($guessed);
    }

    public function test_the_status_keeps_noise_out_of_the_server_errors(): void
    {
        $classifier = new ServerErrorClassifier;

        // With `status_codes` defaulting to 500, these two are parsed and then
        // simply not stored - which is what a scanner sweep should cost.
        $this->assertSame(404, $classifier->statusFor(ServerErrorClassifier::MISSING_FILE));
        $this->assertSame(403, $classifier->statusFor(ServerErrorClassifier::PERMISSION));

        $this->assertSame(500, $classifier->statusFor(ServerErrorClassifier::MEMORY_EXHAUSTED));
        $this->assertSame(500, $classifier->statusFor(ServerErrorClassifier::UNKNOWN));
    }
}
