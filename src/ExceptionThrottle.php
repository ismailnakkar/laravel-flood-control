<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;
use Throwable;
use WeakMap;

/**
 * The rate limit for reported exceptions. Registered as a throttle callback, so it gates inside
 * `shouldntReport()` — ahead of the log write and ahead of every `reportable` callback.
 */
final class ExceptionThrottle
{
    /** @var WeakMap<Throwable, Limit>|null Per-call overrides from Report::exception(). */
    private static ?WeakMap $overrides = null;

    /** @param Throwable $e Required: the handler matches callbacks on this parameter's type. */
    public static function for(Throwable $e): Limit
    {
        $config = app(ThrottleConfig::class);

        // Ahead of the override lookup: disabling beats a per-call limit.
        if (! $config->enabled()) {
            return Limit::none();
        }

        $override = self::takeOverride($e);

        if ($override instanceof Limit) {
            return $override;
        }

        ['limit' => $limit, 'window' => $window] = $config->for($e);

        return self::limit($limit, $window);
    }

    /**
     * Keyed by the throwable, so the override dies with the instance. The Limit is kept whole: one
     * carrying `by()` replaces the per-class bucket.
     */
    public static function override(Throwable $e, Limit $limit): void
    {
        self::overrides()[$e] = $limit->maxAttempts > 0 && $limit->decaySeconds > 0
            ? $limit
            : Limit::none();
    }

    /**
     * Consumed on read: an override caps the report it was set on, not every later report of the
     * same instance. The `getPrevious()` walk covers `$exceptions->map()`, which replaces the
     * throwable before `shouldntReport()` runs — the override may then sit on a previous.
     */
    private static function takeOverride(Throwable $e): ?Limit
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (isset(self::overrides()[$current])) {
                $limit = self::overrides()[$current];
                unset(self::overrides()[$current]);

                return $limit;
            }
        }

        return null;
    }

    /** A limit or window below 1 means no limit, not "report nothing": an empty env var reads as 0. */
    private static function limit(int $limit, int $window): Limit
    {
        return $limit > 0 && $window > 0
            // Empty key: the handler falls back to one bucket per exception class, hashed.
            ? new Limit(maxAttempts: $limit, decaySeconds: $window)
            : Limit::none();
    }

    /** @return WeakMap<Throwable, Limit> */
    private static function overrides(): WeakMap
    {
        return self::$overrides ??= new WeakMap;
    }
}
