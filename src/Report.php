<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * `report($e)` with the two things a call site sometimes needs: extra context, and a limit that
 * differs from the configured one. Without either, use `report($e)` directly — this adds nothing.
 */
final class Report
{
    /**
     * @param  array<string, mixed>  $context  Added for this report only; restored afterwards.
     * @param  Limit|null  $limit  The ceiling for this report: Limit::perHour(1) and friends, or
     *                             new Limit(maxAttempts: 1, decaySeconds: 300).
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
}
