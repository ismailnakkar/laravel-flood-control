<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Cache\RateLimiting\Limit;
use Throwable;
use WeakMap;

/**
 * @internal
 *
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

        return $config->for($e)->toLimit();
    }

    /**
     * Keyed by the throwable, so the override dies with the instance. One carrying `by()` replaces
     * the per-class bucket; one without gets a per-class override bucket of its own.
     */
    public static function override(Throwable $e, Limit $limit): void
    {
        if (Budget::of($limit->maxAttempts, $limit->decaySeconds)->isUnlimited()) {
            self::overrides()[$e] = Limit::none();

            return;
        }

        // A key-less one would land in the class bucket, whose lifetime the first hit fixes — so its
        // window would govern the class's plain reports. Cloned: by() mutates, and a caller may
        // reuse one Limit across classes. Falsy, as the handler tests it.
        self::overrides()[$e] = $limit->key
            ? $limit
            : (clone $limit)->by('flood-control:override:' . $e::class);
    }

    /** Drops an override report() never consumed. */
    public static function forget(Throwable $e): void
    {
        self::takeOverride($e);
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

    /** @return WeakMap<Throwable, Limit> */
    private static function overrides(): WeakMap
    {
        return self::$overrides ??= new WeakMap;
    }
}
