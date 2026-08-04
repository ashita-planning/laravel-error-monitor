<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;

/**
 * Attaches the Laravel exception that explains an Apache 5xx, when there is one.
 *
 * The access log knows a request failed; the application log knows why. Neither
 * carries the other's identity, so the two are matched on what they do share -
 * a request id if the server was configured to log one, otherwise the moment,
 * the method and the normalized path.
 *
 * A match is a judgement, not a fact, so every correlated event records how it
 * was reached and how much that is worth. A 5xx with no counterpart is never
 * dropped: a 502 or a 503 never reaches PHP, so having no Laravel entry is the
 * normal shape of the most interesting failures here.
 */
final class ApacheLaravelCorrelationService
{
    /** No candidate was found; the access event stands on its own. */
    public const METHOD_NONE = 'none';

    /** A request id present on both sides matched exactly. */
    public const METHOD_REQUEST_ID = 'request_id';

    /** Same moment, same HTTP method, same normalized path. */
    public const METHOD_TIME_METHOD_PATH = 'time_method_path';

    /** Same moment and same normalized path, method unknown or different. */
    public const METHOD_TIME_PATH = 'time_path';

    /** Nothing but proximity in time. */
    public const METHOD_TIME = 'time';

    /** Confidence attached to each matching method. */
    private const CONFIDENCE = [
        self::METHOD_REQUEST_ID => 1.0,
        self::METHOD_TIME_METHOD_PATH => 0.8,
        self::METHOD_TIME_PATH => 0.6,
        self::METHOD_TIME => 0.3,
        self::METHOD_NONE => 0.0,
    ];

    /**
     * @param  LogNormalizer  $normalizer  Normalizes both sides' paths before comparing them.
     * @param  int  $windowSeconds  How far apart the two entries may be.
     */
    public function __construct(
        private readonly LogNormalizer $normalizer,
        private readonly int $windowSeconds,
    ) {}

    /**
     * Annotate each access event with the Laravel event that explains it.
     *
     * @param  array<int, ErrorEventData>  $accessEvents
     * @param  array<int, ErrorEventData>  $laravelEvents
     * @return array<int, ErrorEventData>
     */
    public function correlate(array $accessEvents, array $laravelEvents): array
    {
        return array_map(
            fn (ErrorEventData $accessEvent): ErrorEventData => $this->correlateOne($accessEvent, $laravelEvents),
            array_values($accessEvents),
        );
    }

    /**
     * Annotate one access event.
     *
     * @param  array<int, ErrorEventData>  $laravelEvents
     */
    public function correlateOne(ErrorEventData $accessEvent, array $laravelEvents): ErrorEventData
    {
        [$method, $candidates] = $this->candidates($accessEvent, $laravelEvents);

        if ($candidates === []) {
            return $accessEvent->with(metadata: array_merge($accessEvent->metadata, [
                'correlation_method' => self::METHOD_NONE,
                'correlation_confidence' => self::CONFIDENCE[self::METHOD_NONE],
                'correlation_candidates' => 0,
            ]));
        }

        $match = $this->closest($accessEvent, $candidates);

        return $accessEvent->with(metadata: array_merge($accessEvent->metadata, [
            'correlation_method' => $method,
            // Several equally plausible candidates mean the pick is a guess
            // among them, and the confidence has to say so.
            'correlation_confidence' => count($candidates) > 1
                ? round(self::CONFIDENCE[$method] / count($candidates), 2)
                : self::CONFIDENCE[$method],
            'correlation_candidates' => count($candidates),
            'correlated_exception_class' => $match->exceptionClass,
            'correlated_message' => $match->normalizedMessage,
            'correlated_file' => $match->file,
            'correlated_line' => $match->line,
        ]));
    }

    /**
     * Best available matching method and everything it matched.
     *
     * The methods are tried strongest first and the first one that finds
     * anything wins, so a weaker signal never dilutes a stronger one.
     *
     * @param  array<int, ErrorEventData>  $laravelEvents
     * @return array{0: string, 1: array<int, ErrorEventData>}
     */
    private function candidates(ErrorEventData $accessEvent, array $laravelEvents): array
    {
        $requestId = $this->requestId($accessEvent);

        if ($requestId !== null) {
            $matches = array_values(array_filter(
                $laravelEvents,
                fn (ErrorEventData $event): bool => $this->requestId($event) === $requestId,
            ));

            if ($matches !== []) {
                return [self::METHOD_REQUEST_ID, $matches];
            }
        }

        $inWindow = array_values(array_filter(
            $laravelEvents,
            fn (ErrorEventData $event): bool => $this->withinWindow($accessEvent, $event),
        ));

        if ($inWindow === []) {
            return [self::METHOD_NONE, []];
        }

        $path = $this->normalizer->normalizeRoute($accessEvent->route);

        if ($path !== null) {
            $samePath = array_values(array_filter(
                $inWindow,
                fn (ErrorEventData $event): bool => $this->normalizer->normalizeRoute($event->route) === $path,
            ));

            if ($samePath !== []) {
                $sameMethod = array_values(array_filter(
                    $samePath,
                    static fn (ErrorEventData $event): bool => $event->method !== null
                        && $accessEvent->method !== null
                        && $event->method === $accessEvent->method,
                ));

                return $sameMethod !== []
                    ? [self::METHOD_TIME_METHOD_PATH, $sameMethod]
                    : [self::METHOD_TIME_PATH, $samePath];
            }
        }

        return [self::METHOD_TIME, $inWindow];
    }

    /** @param  array<int, ErrorEventData>  $candidates */
    private function closest(ErrorEventData $accessEvent, array $candidates): ErrorEventData
    {
        usort(
            $candidates,
            fn (ErrorEventData $a, ErrorEventData $b): int => $this->distance($accessEvent, $a)
                <=> $this->distance($accessEvent, $b),
        );

        return $candidates[0];
    }

    private function withinWindow(ErrorEventData $accessEvent, ErrorEventData $laravelEvent): bool
    {
        return $this->distance($accessEvent, $laravelEvent) <= $this->windowSeconds;
    }

    private function distance(ErrorEventData $accessEvent, ErrorEventData $laravelEvent): int
    {
        return abs($accessEvent->occurredAt->getTimestamp() - $laravelEvent->occurredAt->getTimestamp());
    }

    /** Request id from the event context, whichever spelling the server used. */
    private function requestId(ErrorEventData $event): ?string
    {
        foreach (['request_id', 'x_request_id', 'unique_id', 'correlation_id'] as $key) {
            $value = $event->context[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
