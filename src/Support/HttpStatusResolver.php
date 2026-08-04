<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Support;

/**
 * Maps a throwable class name to the HTTP status the framework would answer.
 *
 * Laravel logs client errors (404, 419, 422, ...) at the same `ERROR` level as
 * server errors, so the status has to be derived from the exception class
 * before the configured `status_codes` filter can do anything useful.
 *
 * The mapping is deliberately incomplete: {@see mapped()} answers `null` for
 * everything it does not know, so a caller can record that a 500 was assumed
 * rather than presenting the assumption as a fact.
 */
final class HttpStatusResolver
{
    /** Status reported for a throwable the mapping does not know. */
    public const ASSUMED_STATUS = 500;

    /**
     * Short class name to HTTP status.
     *
     * @var array<string, int>
     */
    private const STATUS_MAP = [
        'AccessDeniedHttpException' => 403,
        'AuthenticationException' => 401,
        'AuthorizationException' => 403,
        'BadRequestHttpException' => 400,
        'ConflictHttpException' => 409,
        'GoneHttpException' => 410,
        'LengthRequiredHttpException' => 411,
        'MaintenanceModeException' => 503,
        'MethodNotAllowedHttpException' => 405,
        'ModelNotFoundException' => 404,
        'NotAcceptableHttpException' => 406,
        'NotFoundHttpException' => 404,
        'PreconditionFailedHttpException' => 412,
        'PreconditionRequiredHttpException' => 428,
        'RecordsNotFoundException' => 404,
        'ServiceUnavailableHttpException' => 503,
        'ThrottleRequestsException' => 429,
        'TokenMismatchException' => 419,
        'TooManyRequestsHttpException' => 429,
        'UnauthorizedHttpException' => 401,
        'UnprocessableEntityHttpException' => 422,
        'UnsupportedMediaTypeHttpException' => 415,
        'ValidationException' => 422,
    ];

    /** Resolve the HTTP status a throwable class translates to. */
    public function resolve(?string $exceptionClass): int
    {
        return $this->mapped($exceptionClass) ?? self::ASSUMED_STATUS;
    }

    /**
     * Resolve the status only when the throwable is explicitly mapped.
     *
     * `null` means "the status has to be assumed", which the parsers record in
     * the event metadata instead of stating it as a fact.
     */
    public function mapped(?string $exceptionClass): ?int
    {
        if ($exceptionClass === null || $exceptionClass === '') {
            return null;
        }

        $shortName = ltrim(strrchr('\\'.$exceptionClass, '\\') ?: '', '\\');

        return self::STATUS_MAP[$shortName] ?? null;
    }
}
