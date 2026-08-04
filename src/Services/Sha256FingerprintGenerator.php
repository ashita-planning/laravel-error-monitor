<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use Apkk\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\StackFrameData;

/**
 * SHA-256 fingerprint generator.
 *
 * The material is built in a fixed key order and rendered as JSON, so the same
 * failure always hashes to the same 64 character digest. Which values take part
 * in the identity is configurable through `error-monitor.fingerprint`.
 */
final class Sha256FingerprintGenerator implements FingerprintGenerator
{
    public function __construct(private readonly LogNormalizer $normalizer) {}

    public function generate(ErrorEventData $event): string
    {
        return hash('sha256', json_encode($this->material($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function material(ErrorEventData $event): array
    {
        $includeLine = (bool) config('error-monitor.fingerprint.include_line_number', true);

        $frames = $this->identifyingFrames($event);
        $firstFrame = $frames[0] ?? null;

        $material = [
            'environment' => $event->environment,
            'source' => $event->source,
            'exception_class' => $event->exceptionClass,
            'normalized_message' => $event->normalizedMessage,
            'file' => $firstFrame?->file ?? $event->file,
        ];

        if ($includeLine) {
            $material['line'] = $firstFrame?->line ?? $event->line;
        }

        if ((bool) config('error-monitor.fingerprint.include_method', true)) {
            $material['method'] = $event->method;
        }

        if ((bool) config('error-monitor.fingerprint.include_route', true)) {
            $material['route'] = $this->normalizer->normalizeRoute($event->route);
        }

        $material['application_frames'] = array_map(
            static fn (StackFrameData $frame): array => array_filter([
                'file' => $frame->file,
                'line' => $includeLine ? $frame->line : null,
                'class' => $frame->class,
                'function' => $frame->function,
            ], static fn (mixed $value): bool => $value !== null),
            array_slice($frames, 0, max(1, (int) config('error-monitor.fingerprint.stack_frame_limit', 3))),
        );

        return $material;
    }

    /**
     * Frames that identify the failure.
     *
     * Application frames only, so the identity stays attached to the code the
     * team owns. A trace made entirely of vendor frames falls back to those, so
     * a failure raised inside the framework is still identifiable.
     *
     * @return array<int, StackFrameData>
     */
    private function identifyingFrames(ErrorEventData $event): array
    {
        $applicationFrames = $event->applicationFrames();

        return $applicationFrames !== [] ? $applicationFrames : array_values($event->stackFrames);
    }
}
