<?php

declare(strict_types=1);

namespace FloodControl;

use BackedEnum;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;
use Throwable;
use UnitEnum;

/**
 * Throttled log lines. Keep keys code-owned: a key built from request data is unbounded cache
 * cardinality.
 */
final class LogThrottle
{
    /**
     * The real logger once per $seconds for $key, a discarding one after; a null or sub-1 window
     * never throttles. Exactly once, even across workers: the gate is one Cache::add().
     *
     * $channel: an array is an on-demand stack; null is the default channel.
     *
     * @param  LoggerInterface|UnitEnum|array<int, string>|string|null  $channel
     */
    public static function once(
        string $key,
        ?int $seconds,
        LoggerInterface|UnitEnum|array|string|null $channel = null,
    ): LoggerInterface {
        $budget = Budget::of(1, $seconds ?? 0);
        $logger = self::logger($channel);

        if ($budget->isUnlimited()) {
            return $logger;
        }

        try {
            $cache = app('cache')->store(config('cache.limiter'));

            // Repository::add() is always false on a NullStore, which would discard every line.
            if ($cache->getStore() instanceof NullStore) {
                return $logger;
            }

            return $cache->add(self::cacheKey($key), true, $budget->seconds) ? $logger : self::discard();
        } catch (Throwable) {
            // Fail open: an outage is when this line matters most.
            return $logger;
        }
    }

    /**
     * The real logger for the first $budget->times calls per window, a discarding one after. About
     * N: workers racing the counter can let an extra one through, so prefer once() unless you want
     * several samples.
     *
     * @param  LoggerInterface|UnitEnum|array<int, string>|string|null  $channel
     */
    public static function times(
        string $key,
        Budget $budget,
        LoggerInterface|UnitEnum|array|string|null $channel = null,
    ): LoggerInterface {
        $logger = self::logger($channel);

        if ($budget->isUnlimited()) {
            return $logger;
        }

        try {
            return app(RateLimiter::class)->attempt(
                self::cacheKey($key),
                $budget->times,
                static fn (): bool => true,
                $budget->seconds,
            ) ? $logger : self::discard();
        } catch (Throwable) {
            return $logger;
        }
    }

    /** @param  LoggerInterface|UnitEnum|array<int, string>|string|null  $channel */
    private static function logger(LoggerInterface|UnitEnum|array|string|null $channel): LoggerInterface
    {
        if ($channel instanceof UnitEnum) {
            // LogManager only resolves enums itself from Laravel 13.3; before that it trims them.
            $channel = $channel instanceof BackedEnum ? (string)$channel->value : $channel->name;
        }

        return match (true) {
            // The manager, not Log::driver(): a bare Log::spy() stubs driver() to return null.
            $channel === null                   => app('log'),
            $channel instanceof LoggerInterface => $channel,
            is_array($channel)                  => Log::stack($channel),
            default                             => Log::channel($channel),
        };
    }

    /**
     * A Logger, not a NullLogger, so withContext() and array messages still work. With no handlers
     * or dispatcher it writes nothing and fires no MessageLogged.
     */
    private static function discard(): LoggerInterface
    {
        return new Logger(new Monolog('flood-control'));
    }

    private static function cacheKey(string $key): string
    {
        // Hashed: memcached rejects long keys, and a rejected add() reads as already logged.
        return 'log-throttle:' . hash('xxh128', $key);
    }
}
