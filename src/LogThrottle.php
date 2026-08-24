<?php

declare(strict_types=1);

namespace FloodControl;

use BackedEnum;
use Illuminate\Cache\NullStore;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;
use Throwable;
use UnitEnum;

/**
 * One log line per key per window, for narration that is not an exception.
 *
 * A log line has no class to key on, so the key is yours to pick. Keep it a literal or a code-owned
 * value: a key built from a request header is unbounded cache cardinality.
 */
final class LogThrottle
{
    /**
     * The real logger once per $seconds for $key, a discarding one after. A null or sub-1 window
     * never throttles.
     *
     * $channel takes a channel or stack name, an array of channel names for an on-demand stack, an
     * enum, a logger you already hold, or null for the default channel.
     *
     * @param  LoggerInterface|UnitEnum|array<int, string>|string|null  $channel
     */
    public static function once(
        string $key,
        ?int $seconds,
        LoggerInterface|UnitEnum|array|string|null $channel = null,
    ): LoggerInterface {
        $logger = self::logger($channel);

        if ($seconds === null || $seconds < 1) {
            return $logger;
        }

        try {
            $cache = app('cache')->store(config('cache.limiter'));

            // NullStore has no add(), so Repository::add() always returns false. Fail open.
            if ($cache->getStore() instanceof NullStore) {
                return $logger;
            }

            return $cache->add(self::cacheKey($key), true, $seconds) ? $logger : self::discard();
        } catch (Throwable) {
            // Never throw into the caller: a cache outage is exactly when this line matters.
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
            $channel === null                   => Log::driver(),
            $channel instanceof LoggerInterface => $channel,
            is_array($channel)                  => Log::stack($channel),
            default                             => Log::channel($channel),
        };
    }

    /**
     * A Logger, not a NullLogger, so a suppressed line accepts everything the real one does —
     * withContext(), array messages, every PSR level. No handlers and no dispatcher, so it writes
     * nothing and fires no MessageLogged.
     */
    private static function discard(): LoggerInterface
    {
        return new Logger(new Monolog('flood-control'));
    }

    private static function cacheKey(string $key): string
    {
        // Hashed like the exception throttle's: a raw key can exceed what memcached accepts, and a
        // rejected write reads as "already logged".
        return 'log-throttle:' . hash('xxh128', $key);
    }
}
