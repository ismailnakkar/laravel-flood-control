<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Context;
use Throwable;

/** report() with scoped context, a per-call limit, or both. */
final class Report
{
    /**
     * @param  array<string, mixed>  $context  Scoped to this report.
     * @param  Limit|null  $limit  Caps this report only; by() picks the bucket.
     */
    public static function exception(Throwable $e, array $context = [], ?Limit $limit = null): void
    {
        if ($limit !== null) {
            ExceptionThrottle::override($e, $limit);
        }

        try {
            $context === []
                ? report($e)
                : Context::scope(fn () => report($e), $context);
        } finally {
            // report() may return before the throttle; don't leave the override for a later report.
            ExceptionThrottle::forget($e);
        }
    }

    /**
     * Reports $message as an OperationalError, bucketed per call site so one noisy caller cannot
     * spend another's budget.
     *
     * @param  array<string, mixed>  $context  Scoped to this report.
     * @param  Throwable|null  $previous  Chained, so the sink keeps its stack trace.
     * @param  Budget|null  $budget  This call site's pace; null takes the configured one.
     */
    public static function error(
        string $message,
        array $context = [],
        ?Throwable $previous = null,
        ?Budget $budget = null,
    ): void {
        $e = new OperationalError($message, previous: $previous);

        $limit = ($budget ?? app(ThrottleConfig::class)->for($e))->toLimit(self::callSite());

        self::exception($e, $context, $limit);
    }

    /** The caller's file and line: bounded by the source, unlike the message. */
    private static function callSite(): string
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        // eval()'d or native code has no file. One shared bucket beats keying on the message.
        return isset($frame['file'])
            ? 'flood-control:error:' . hash('xxh128', $frame['file'] . ':' . ($frame['line'] ?? 0))
            : 'flood-control:error';
    }
}
