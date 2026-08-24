<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;
use Throwable;
use WeakMap;

/**
 * The rate limit for reported exceptions. Registered as a throttle callback, so it gates inside
 * `shouldntReport()` — ahead of the log write, ahead of Sentry, and ahead of every `reportable`
 * callback. That last part is why anything counting exceptions has to be registered as a throttle
 * callback too, not as a `reportable`. See the README.
 */
final class ExceptionThrottle
{
    /** @var WeakMap<Throwable, Limit>|null Per-call overrides from Report::exception(). */
    private static ?WeakMap $overrides = null;

    /** @param Throwable $e Required: the handler matches callbacks on this parameter's type. */
    public static function for(Throwable $e): Limit
    {
        $config = app(ThrottleConfig::class);

        // Before the override lookup, so the kill switch really kills.
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
     * Keyed by the throwable itself, so the override travels with that instance and dies with it.
     * Prefer the `classes` config for a rule that always applies to a class.
     */
    public static function override(Throwable $e, Limit $limit): void
    {
        self::overrides()[$e] = self::limit($limit->maxAttempts, $limit->decaySeconds);
    }

    /**
     * Read and forget: an override is the ceiling for the report it was set on. Leaving it would
     * also apply to any later report of the same instance, in this request or a later one.
     *
     * The `getPrevious()` walk covers `$exceptions->map()`, which replaces the throwable before
     * `shouldntReport()` runs — the mapped exception carries the original as its previous.
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

    /**
     * A budget below 1 means no limit, never "report nothing": an empty env var reads as 0, and
     * failing closed there would lose every error in production with nothing to show for it.
     */
    private static function limit(int $limit, int $window): Limit
    {
        return $limit > 0 && $window > 0
            // No key: the handler defaults to one bucket per exception class, hashed.
            ? new Limit(maxAttempts: $limit, decaySeconds: $window)
            : Limit::none();
    }

    /** @return WeakMap<Throwable, Limit> */
    private static function overrides(): WeakMap
    {
        return self::$overrides ??= new WeakMap;
    }
}
