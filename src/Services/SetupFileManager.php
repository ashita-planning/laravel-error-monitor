<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use RuntimeException;

/** Add missing settings only; never disclose existing environment values. */
final class SetupFileManager
{
    /**
     * @param  array<string, string>  $defaults
     * @return array{config: string, environment: string, added_keys: array<int, string>}
     */
    public function setup(string $environmentPath, string $configPath, string $sourcePath, array $defaults, bool $dryRun): array
    {
        foreach ($defaults as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) || preg_match('/[\r\n\x00]/', $value)) {
                throw new RuntimeException('Invalid setup value.');
            }
        }

        foreach ([$environmentPath, $configPath] as $path) {
            if (is_link($path) || (file_exists($path) && ! is_file($path))) {
                throw new RuntimeException('Setup target must be a regular file, not a symlink.');
            }
        }

        $configExists = is_file($configPath);
        $environmentExists = is_file($environmentPath);
        $content = $environmentExists ? file_get_contents($environmentPath) : '';
        if ($content === false) {
            throw new RuntimeException('Could not read environment file.');
        }

        $missing = $this->missing($content, $defaults);
        if (! $dryRun) {
            // Exclusive creation preserves existing and concurrently published config.
            if (! $configExists) {
                $source = file_get_contents($sourcePath);
                if ($source === false || ! is_dir(dirname($configPath))) {
                    throw new RuntimeException('Could not prepare configuration file.');
                }
                $handle = @fopen($configPath, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Configuration target changed or is not writable.');
                }
                try {
                    if (fwrite($handle, $source) !== strlen($source)) {
                        throw new RuntimeException('Could not write configuration file.');
                    }
                } finally {
                    fclose($handle);
                }
            }

            if ($missing !== []) {
                $handle = @fopen($environmentPath, $environmentExists ? 'r+' : 'x+');
                if ($handle === false) {
                    throw new RuntimeException('Environment target changed or is not writable.');
                }
                try {
                    if (! $environmentExists && ! chmod($environmentPath, 0600)) {
                        throw new RuntimeException('Could not restrict environment file permissions.');
                    }
                    if (! flock($handle, LOCK_EX)) {
                        throw new RuntimeException('Could not lock environment file.');
                    }
                    $current = stream_get_contents($handle);
                    if ($current === false) {
                        throw new RuntimeException('Could not read environment file.');
                    }
                    // Recheck under the lock to avoid duplicate appends.
                    $missing = $this->missing($current, $defaults);
                    $append = $current !== '' && ! str_ends_with($current, "\n") ? "\n" : '';
                    foreach ($missing as $key => $value) {
                        $quoted = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
                        $append .= $key.'="'.$quoted.'"'."\n";
                    }
                    if ($missing !== [] && (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $append) !== strlen($append))) {
                        throw new RuntimeException('Could not append environment settings.');
                    }
                } finally {
                    fclose($handle);
                }
            }
        }

        return [
            'config' => $configExists ? 'preserved' : ($dryRun ? 'would_create' : 'created'),
            'environment' => $dryRun ? 'preview' : 'updated',
            'added_keys' => array_keys($missing),
        ];
    }

    /** @param array<string, string> $defaults
     * @return array<string, string>
     */
    private function missing(string $content, array $defaults): array
    {
        return array_filter($defaults, static fn (string $key): bool => ! preg_match(
            '/^\h*(?:export\h+)?'.preg_quote($key, '/').'\h*=/m', $content,
        ), ARRAY_FILTER_USE_KEY);
    }
}
