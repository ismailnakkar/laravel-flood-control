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
}
