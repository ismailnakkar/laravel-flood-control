<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The other half of the exception throttle: one log line per key per window, for the narration that
 * is not an exception — a denied origin, a circuit opening, a feed going stale.
 *
 * A log line has no class to key on, so unlike ExceptionThrottle the key is yours to pick. Keep it a
 * literal or a code-owned value: a key built from a request header is unbounded cache cardinality.
 */
final class LogThrottle
{
    /**
     * The real logger once per $seconds for $key, a NullLogger after. A null window never throttles.
     *
     * Pair a throttled line with an unthrottled counter. The line tells you what happened; without
     * the counter there is no way to tell one blip from every call failing.
     */
    public static function once(string $key, ?int $seconds, ?string $channel = null): LoggerInterface
    {
        $logger = $channel === null ? Log::driver() : Log::channel($channel);

        if ($seconds === null || $seconds < 1) {
            return $logger;
        }

        try {
            return app(Cache::class)->add(self::cacheKey($key), true, $seconds)
                ? $logger
                : new NullLogger;
        } catch (Throwable) {
            // Logging must never break its caller: the outage may be the cache this gate needs, and
            // that is exactly when the line is worth having.
            return $logger;
        }
    }

    private static function cacheKey(string $key): string
    {
        return 'log-throttle:' . $key;
    }
}
