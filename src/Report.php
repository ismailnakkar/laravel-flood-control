<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Context;
use Throwable;

/** `report($e)` with extra context, a per-call limit, or both. With neither, use `report($e)`. */
final class Report
{
    /**
     * @param  array<string, mixed>  $context  Added for this report only; restored afterwards.
     * @param  Limit|null  $limit  Ceiling for this report. `by()` picks the bucket.
     */
    public static function exception(Throwable $e, array $context = [], ?Limit $limit = null): void
    {
        if ($limit !== null) {
            ExceptionThrottle::override($e, $limit);
        }

        $context === []
            ? report($e)
            : Context::scope(fn () => report($e), $context);
    }

    /**
     * A message that deserves an issue rather than a log line, reported behind the gate.
     *
     * For narration that only ever belongs in the file, use `LogThrottle::once()` instead. This
     * still writes the log line too — `report()` does that itself once the gate lets it past.
     *
     * @param  array<string, mixed>  $context  Added for this report only; restored afterwards.
     * @param  Throwable|null  $previous  The cause, chained so the sink keeps its stack trace.
     */
    public static function error(string $message, array $context = [], ?Throwable $previous = null): void
    {
        $e = new OperationalError($message, previous: $previous);

        // Bucketed on the call site, not on OperationalError: one shared bucket would let the
        // noisiest caller spend the budget for every other one.
        $limit = app(ThrottleConfig::class)->for($e)->toLimit(self::callSite());

        self::exception($e, $context, $limit);
    }

    /**
     * The caller's file and line. Code-owned, so the key space is bounded by the source — unlike
     * the message, which interpolates values.
     */
    private static function callSite(): string
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        // eval()'d or native code has no file. One shared bucket beats keying on the message.
        return isset($frame['file'])
            ? 'flood-control:error:' . hash('xxh128', $frame['file'] . ':' . ($frame['line'] ?? 0))
            : 'flood-control:error';
    }
}
