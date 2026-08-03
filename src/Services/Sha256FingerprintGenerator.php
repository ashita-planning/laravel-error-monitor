<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Services;

use AshitaPlanning\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use AshitaPlanning\LaravelErrorMonitor\DTO\ErrorEventData;
use AshitaPlanning\LaravelErrorMonitor\DTO\StackFrameData;

final class Sha256FingerprintGenerator implements FingerprintGenerator
{
    public function generate(ErrorEventData $event): string
    {
        return hash('sha256', json_encode($this->material($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function material(ErrorEventData $event): array
    {
        $applicationFrames = array_values(array_filter(
            $event->stackFrames,
            static fn (StackFrameData $frame): bool => $frame->isApplicationFrame,
        ));
        $firstFrame = $applicationFrames[0] ?? null;

        return [
            'environment' => $event->environment,
            'source' => $event->source,
            'exception_class' => $event->exceptionClass,
            'normalized_message' => $event->normalizedMessage,
            'file' => $firstFrame?->file ?? $event->file,
            'line' => $firstFrame?->line ?? $event->line,
            'method' => $event->method,
            'route' => $this->normalizeRoute($event->route),
            'application_frames' => array_map(
                static fn (StackFrameData $frame): array => [
                    'file' => $frame->file,
                    'line' => $frame->line,
                    'class' => $frame->class,
                    'function' => $frame->function,
                ],
                array_slice($applicationFrames, 0, max(1, (int) config('error-monitor.fingerprint.stack_frame_limit', 3))),
            ),
        ];
    }

    private function normalizeRoute(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        return preg_replace('/\?.*$/', '?{query}', $route) ?: $route;
    }
}
