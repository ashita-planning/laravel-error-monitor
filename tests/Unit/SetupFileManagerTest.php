<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\Services\SetupFileManager;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SetupFileManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/error-monitor-setup-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/source.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (file_exists($this->directory.'/.env')) {
            unlink($this->directory.'/.env');
        }
        rmdir($this->directory);
    }

    public function test_preview_writes_nothing_and_reports_only_keys(): void
    {
        $result = $this->applySetup(['EXAMPLE_TOKEN' => 'fake-secret'], true);
        $this->assertSame(['EXAMPLE_TOKEN'], $result['added_keys']);
        $this->assertStringNotContainsString('fake-secret', json_encode($result));
        $this->assertFileDoesNotExist($this->directory.'/.env');
        $this->assertFileDoesNotExist($this->directory.'/config.php');
    }

    public function test_existing_values_comments_and_configuration_survive_repeated_setup(): void
    {
        $original = "# keep this\nexport EXAMPLE_ENABLED = false\nEXAMPLE_TOKEN=\"fake-existing-secret\"";
        file_put_contents($this->directory.'/.env', $original);
        file_put_contents($this->directory.'/config.php', '<?php return ["custom" => true];');
        $defaults = ['EXAMPLE_ENABLED' => 'true', 'EXAMPLE_TOKEN' => 'replacement', 'EXAMPLE_REPOSITORY' => 'acme/shop'];
        $this->assertSame(['EXAMPLE_REPOSITORY'], $this->applySetup($defaults)['added_keys']);
        $after = file_get_contents($this->directory.'/.env');
        $this->assertStringStartsWith($original, $after);
        $this->assertSame([], $this->applySetup($defaults)['added_keys']);
        $this->assertSame($after, file_get_contents($this->directory.'/.env'));
        $this->assertSame('<?php return ["custom" => true];', file_get_contents($this->directory.'/config.php'));
    }

    public function test_new_environment_is_private_and_values_round_trip_without_interpolation(): void
    {
        $value = 'path\\with "quotes" $OTHER # trailing';
        $this->applySetup(['EXAMPLE_PATH' => $value]);
        $this->assertSame($value, Dotenv::parse(file_get_contents($this->directory.'/.env'))['EXAMPLE_PATH']);
        $this->assertSame(0600, fileperms($this->directory.'/.env') & 0777);
    }

    public function test_symlink_target_is_rejected_without_changing_referent(): void
    {
        file_put_contents($this->directory.'/other', 'unchanged');
        symlink($this->directory.'/other', $this->directory.'/.env');
        try {
            $this->applySetup(['EXAMPLE_ENABLED' => 'true']);
            $this->fail('Expected rejection.');
        } catch (RuntimeException) {
            $this->assertSame('unchanged', file_get_contents($this->directory.'/other'));
        } finally {
            unlink($this->directory.'/.env');
        }
    }

    public function test_multiline_input_is_rejected_before_any_write(): void
    {
        $this->expectException(RuntimeException::class);
        $this->applySetup(['EXAMPLE_ENABLED' => "true\nOTHER=value"]);
    }

    /** @param array<string, string> $defaults */
    private function applySetup(array $defaults, bool $dryRun = false): array
    {
        return (new SetupFileManager)->setup($this->directory.'/.env', $this->directory.'/config.php', $this->directory.'/source.php', $defaults, $dryRun);
    }
}
