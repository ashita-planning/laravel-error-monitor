<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Support;

use Apkk\LaravelErrorMonitor\Services\Sha256FingerprintGenerator;

/**
 * Decides whether a stack frame belongs to the application or to a dependency.
 *
 * Only application frames take part in the fingerprint, which keeps the identity
 * of a failure attached to the code the team actually owns. A trace holding no
 * application frame at all falls back to its vendor frames in
 * {@see Sha256FingerprintGenerator}, so a failure raised entirely inside the
 * framework stays identifiable.
 */
final class ApplicationFrameDetector
{
    /**
     * @param  array<int, string>  $applicationPaths  Path fragments marking application code.
     * @param  array<int, string>  $vendorPaths  Path fragments marking third party code.
     */
    public function __construct(
        private readonly array $applicationPaths,
        private readonly array $vendorPaths,
    ) {}

    /**
     * Whether the given file belongs to the application.
     *
     * A vendor fragment always wins: `vendor/` inside the path means the frame
     * is a dependency even when the deployment root happens to contain `app/`.
     */
    public function isApplication(?string $file): bool
    {
        if ($file === null || $file === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $file);

        foreach ($this->vendorPaths as $vendorPath) {
            if ($vendorPath !== '' && str_contains($normalized, str_replace('\\', '/', $vendorPath))) {
                return false;
            }
        }

        // Without configured application paths every non-vendor frame counts as
        // application code, which is the safer default for an unknown layout.
        if ($this->applicationPaths === []) {
            return true;
        }

        foreach ($this->applicationPaths as $applicationPath) {
            if ($applicationPath !== '' && str_contains($normalized, str_replace('\\', '/', $applicationPath))) {
                return true;
            }
        }

        return false;
    }
}
