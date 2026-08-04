<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Integration\Tests;

use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorIssue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Nothing sensitive survives a run.
 *
 * The individual packages each test their own masking, but only here does a
 * real log with real-shaped secrets in it pass through every stage at once -
 * parsing, masking, fingerprinting, storage, the issue body, the comment, the
 * command's JSON output. A leak at any single handoff would show up nowhere
 * else.
 */
final class SecretLeakTest extends TestCase
{
    /** Values planted in the log that must not come out the other end. */
    private const PLANTED = [
        'email' => 'user@example.invalid',
        'client ip' => '203.0.113.10',
        'bearer token' => 'sample-bearer-token-value-abcdefghijklmnop',
        'session cookie' => 'laravel_session=abcdefghijklmnopqrstuvwxyz012345',
        // Deliberately not shaped like any real provider's key. Masking here is
        // by field name, so the shape buys no coverage - and a fixture that
        // trips secret scanning costs every clone of this repository.
        'api key' => 'integration-fake-api-key-000000000000',
        'password' => 'hunter2-not-a-real-password',
    ];

    #[DataProvider('secrets')]
    public function test_nothing_planted_in_a_log_reaches_any_output(string $label, string $secret): void
    {
        $this->plantSecretsInTheLog();
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        $this->assertStringNotContainsString(
            $secret,
            $this->everythingWritten(),
            sprintf('The %s reached an output it should never reach.', $label),
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function secrets(): array
    {
        $cases = [];

        foreach (self::PLANTED as $label => $secret) {
            $cases[$label] = [$label, $secret];
        }

        return $cases;
    }

    public function test_the_github_token_never_leaves_the_authorization_header(): void
    {
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        Http::recorded(function (Request $request): bool {
            $this->assertStringNotContainsString(self::TOKEN, $request->url());
            $this->assertStringNotContainsString(self::TOKEN, (string) $request->body());

            return false;
        });

        $this->assertStringNotContainsString(self::TOKEN, $this->storedRows());
    }

    public function test_the_token_is_absent_from_the_json_report(): void
    {
        $this->fakeGithub();

        $output = $this->runAndCapture(['--date' => self::DAY, '--json' => true]);

        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertStringNotContainsString('Authorization', $output);
        $this->assertStringContainsString('"events_stored"', $output, 'It is still a report.');
    }

    public function test_the_status_commands_print_no_secrets(): void
    {
        foreach (['error-monitor:status', 'error-monitor:github-status', 'error-monitor:xserver-status'] as $command) {
            $output = $this->runAndCapture([], $command);

            $this->assertStringNotContainsString(self::TOKEN, $output, $command.' printed the token.');
        }
    }

    public function test_a_rejected_token_is_not_echoed_back_in_the_failure(): void
    {
        // GitHub error bodies can quote the request, and the request carried
        // the Authorization header.
        Http::fake(['*' => Http::response([
            'message' => 'Bad credentials',
            'documentation_url' => 'https://docs.github.com/rest',
            'request' => ['headers' => ['Authorization' => 'Bearer '.self::TOKEN]],
        ], 401)]);

        $output = $this->runAndCapture(['--date' => self::DAY, '--json' => true]);

        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertStringNotContainsString('documentation_url', $output);
        // The warning still says what is wrong.
        $this->assertStringContainsString('token', strtolower($output));
    }

    public function test_masking_keeps_the_values_that_identify_a_failure(): void
    {
        // The complement of every test above: removing too much would be its
        // own kind of failure, and silently so.
        $this->plantSecretsInTheLog();
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        $stored = $this->storedRows();

        $this->assertStringContainsString('42S02', $stored, 'The SQLSTATE survives.');
        $this->assertStringContainsString('1146', $stored, 'The driver error code survives.');
        $this->assertStringContainsString('134217728', $stored, 'The memory limit survives.');
        $this->assertStringContainsString('/orders/{id}', $stored, 'The normalized route survives.');
    }

    /** Put realistic secrets into the log the run will read. */
    private function plantSecretsInTheLog(): void
    {
        $path = $this->fixturePath('storage/logs/laravel.log');
        $original = (string) file_get_contents($path);

        $this->beforeApplicationDestroyed(static function () use ($path, $original): void {
            file_put_contents($path, $original);
        });

        $entry = sprintf(
            '[%s 12:00:00] production.ERROR: Checkout failed for %s from %s '
            .'{"method":"post","url":"/checkout","authorization":"Bearer %s","cookie":"%s",'
            .'"api_key":"%s","password":"%s"}'."\n",
            self::DAY,
            self::PLANTED['email'],
            self::PLANTED['client ip'],
            self::PLANTED['bearer token'],
            self::PLANTED['session cookie'],
            self::PLANTED['api key'],
            self::PLANTED['password'],
        );

        file_put_contents($path, $original.$entry);
    }

    /** Everything the run persisted, as one searchable string. */
    private function storedRows(): string
    {
        $rows = [];

        foreach (ErrorMonitorEvent::query()->get() as $event) {
            $rows[] = (string) json_encode($event->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        foreach (ErrorMonitorIssue::query()->get() as $issue) {
            $rows[] = (string) json_encode($issue->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $rows);
    }

    /** Everything persisted plus everything sent to GitHub. */
    private function everythingWritten(): string
    {
        $parts = [$this->storedRows()];

        Http::recorded(function (Request $request) use (&$parts): bool {
            $parts[] = $request->url()."\n".(string) $request->body();

            return false;
        });

        return implode("\n", $parts);
    }

    /**
     * Run a command and read what it actually printed.
     *
     * Testbench mocks the console output by default, which would make every
     * assertion below pass against an empty string - the worst possible outcome
     * for a test whose whole job is to look at that output.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function runAndCapture(array $arguments, string $command = 'error-monitor:run'): string
    {
        $this->withoutMockingConsoleOutput();

        Artisan::call($command, $arguments);

        $output = Artisan::output();

        $this->assertNotSame('', trim($output), 'The command printed nothing, so nothing was checked.');

        return $output;
    }

    private function fakeGithub(): void
    {
        Http::fake([
            '*/search/issues*' => Http::response(['items' => []]),
            '*/repos/*/issues/*/comments*' => Http::response([]),
            '*/repos/*/issues/*/labels*' => Http::response([]),
            '*/repos/*/issues?*' => Http::response([]),
            '*/repos/*/issues' => Http::response([
                'number' => 7,
                'state' => 'open',
                'title' => 'x',
                'body' => 'x',
                'html_url' => 'https://github.invalid/x/issues/7',
                'labels' => [],
            ], 201),
        ]);
    }
}
